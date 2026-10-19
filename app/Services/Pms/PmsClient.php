<?php

namespace App\Services\Pms;

use App\Models\PmsSetting;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Чтение брони из PMS. Два источника на выбор (один формат ответа — `{"booking": {...}}`):
 *  — Hotel Hub: `GET {адрес}/v1/properties/{код}/bookings/{номер}` с токеном (право `bookings`);
 *  — родной Exely: `GET {connect.hopenapi.com}/api/read-reservation/v1/properties/{id}/bookings/{номер}`
 *    с OAuth-токеном (`POST /auth/token`, client_credentials, 15 минут, кешируется).
 * Gifter только читает — броней в PMS не создаёт.
 *
 * Ошибки различаются намеренно: 404 — брони нет (данные свежие); 503 — данные хаба устарели, «не найдено»
 * не говорим; 401/403 — токен; 429 — лимит; сеть и 5xx — PMS недоступна. Во всех случаях бронь не подтверждена.
 */
class PmsClient
{
    public function __construct(private readonly PmsSetting $settings) {}

    public static function make(): self
    {
        return new self(PmsSetting::current());
    }

    public function isActive(): bool
    {
        return $this->settings->isActive();
    }

    /** Свежий запрос (для сохранения брони). */
    public function find(string $number): PmsBooking
    {
        return $this->fetch($number);
    }

    /** С кешем на полминуты — подсказка в форме, чтобы не упереться в лимит 120 запросов в минуту. */
    public function preview(string $number): PmsBooking
    {
        $key = 'pms.preview.'.md5($this->settings->provider.'|'.$this->settings->baseUrl().'|'.$this->settings->property_code.'|'.$number);
        $found = Cache::get($key);
        if ($found instanceof PmsBooking) {
            return $found;
        }
        $booking = $this->fetch($number);
        Cache::put($key, $booking, 30);

        return $booking;
    }

    /**
     * Проверка связи и токена: запрос несуществующей брони. 404 — связь и токен в порядке.
     *
     * @return array{ok: bool, message: string}
     */
    public function ping(): array
    {
        try {
            $this->fetch('00000000-0-0');

            return ['ok' => true, 'message' => 'Связь есть.'];
        } catch (PmsException $e) {
            return str_starts_with($e->getMessage(), 'Бронь №') && str_contains($e->getMessage(), 'не найдена')
                ? ['ok' => true, 'message' => 'Связь есть, токен принят (тестовая бронь не найдена — так и должно быть).']
                : ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    private function fetch(string $number): PmsBooking
    {
        $number = trim($number);
        if (! $this->isActive()) {
            throw new PmsException('Интеграция с PMS не настроена.');
        }
        if ($number === '') {
            throw new PmsException('Укажите номер брони PMS.');
        }

        $response = $this->get($number);
        // Токен Exely живёт 15 минут: если он протух раньше срока — один раз получаем новый.
        if ($response->status() === 401 && $this->settings->isExely()) {
            Cache::forget($this->tokenKey());
            $response = $this->get($number);
        }

        return match (true) {
            $response->successful() => PmsBooking::fromResponse((array) $response->json(), $number),
            $response->status() === 404 => throw new PmsException("Бронь № {$number} не найдена в PMS. Проверьте номер."),
            $response->status() === 503 => throw new PmsException('Данные PMS устарели (нет связи с Exely) — бронь не подтверждена и не сохранена. Попробуйте позже.'),
            $response->status() === 400 => throw new PmsException("Номер брони «{$number}» PMS не принимает — проверьте формат."),
            in_array($response->status(), [401, 403], true) => throw new PmsException($this->settings->isExely()
                ? 'Exely не пускает Gifter в Read Reservation API (нет доступа или неверные ключи) — обратитесь к администратору.'
                : 'PMS отклонила токен Gifter (нет права «bookings» или он неверен) — обратитесь к администратору.'),
            $response->status() === 429 => throw new PmsException('Слишком много запросов к PMS — подождите минуту и повторите.'),
            default => throw new PmsException('PMS вернула ошибку '.$response->status().' — бронь не подтверждена и не сохранена.'),
        };
    }

    private function get(string $number): Response
    {
        $code = rawurlencode((string) $this->settings->property_code);
        // Hotel Hub: /v1/properties/{код}/bookings/{номер}. Родной Exely: Read Reservation API, тот же формат ответа.
        $path = $this->settings->isExely()
            ? "/api/read-reservation/v1/properties/{$code}/bookings/".rawurlencode($number)
            : "/v1/properties/{$code}/bookings/".rawurlencode($number);

        try {
            return $this->request()->get($this->settings->baseUrl().$path);
        } catch (ConnectionException) {
            throw new PmsException('PMS не отвечает — бронь не подтверждена и не сохранена. Попробуйте позже.');
        }
    }

    private function request(): PendingRequest
    {
        $token = $this->settings->isExely() ? $this->exelyToken() : (string) $this->settings->token;

        return Http::withToken($token)->acceptJson()->timeout(8)->connectTimeout(4);
    }

    private function tokenKey(): string
    {
        return 'pms.exely.token.'.md5($this->settings->baseUrl().'|'.$this->settings->client_id);
    }

    /**
     * Токен Exely (OAuth client_credentials): живёт 15 минут, кешируется; запросы токена Exely ограничивает
     * (15 в минуту на IP), поэтому каждый раз новый не берём.
     */
    private function exelyToken(): string
    {
        $cached = Cache::get($this->tokenKey());
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        try {
            $response = Http::asForm()->acceptJson()->timeout(8)->connectTimeout(4)->post($this->settings->baseUrl().'/auth/token', [
                'grant_type' => 'client_credentials',
                'client_id' => $this->settings->client_id,
                'client_secret' => $this->settings->client_secret,
            ]);
        } catch (ConnectionException) {
            throw new PmsException('Exely не отвечает — бронь не подтверждена и не сохранена. Попробуйте позже.');
        }
        if (in_array($response->status(), [400, 401, 403], true)) {
            throw new PmsException('Exely отклонил ключи Gifter (client_id / client_secret) — обратитесь к администратору.');
        }
        if ($response->status() === 429) {
            throw new PmsException('Слишком много запросов токена к Exely — подождите минуту и повторите.');
        }
        $token = (string) $response->json('access_token');
        if (! $response->successful() || $token === '') {
            throw new PmsException('Exely не выдал токен (ошибка '.$response->status().') — бронь не подтверждена и не сохранена.');
        }
        Cache::put($this->tokenKey(), $token, max(60, ((int) $response->json('expires_in', 900)) - 60));

        return $token;
    }
}
