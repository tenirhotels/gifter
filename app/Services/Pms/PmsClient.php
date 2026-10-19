<?php

namespace App\Services\Pms;

use App\Models\PmsSetting;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Чтение брони из PMS: `GET {адрес}/v1/properties/{код}/bookings/{номер}` с токеном (право `bookings`).
 * Gifter только читает — броней в PMS не создаёт. Хаб и прямой Exely отвечают одним форматом.
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
        $key = 'pms.preview.'.md5($this->settings->base_url.'|'.$this->settings->property_code.'|'.$number);
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

        try {
            $response = $this->request()->get(rtrim((string) $this->settings->base_url, '/')
                .'/v1/properties/'.rawurlencode((string) $this->settings->property_code).'/bookings/'.rawurlencode($number));
        } catch (ConnectionException) {
            throw new PmsException('PMS не отвечает — бронь не подтверждена и не сохранена. Попробуйте позже.');
        }

        return match (true) {
            $response->successful() => PmsBooking::fromResponse((array) $response->json(), $number),
            $response->status() === 404 => throw new PmsException("Бронь № {$number} не найдена в PMS. Проверьте номер."),
            $response->status() === 503 => throw new PmsException('Данные PMS устарели (нет связи с Exely) — бронь не подтверждена и не сохранена. Попробуйте позже.'),
            $response->status() === 400 => throw new PmsException("Номер брони «{$number}» PMS не принимает — проверьте формат."),
            in_array($response->status(), [401, 403], true) => throw new PmsException('PMS отклонила токен Gifter (нет права «bookings» или он неверен) — обратитесь к администратору.'),
            $response->status() === 429 => throw new PmsException('Слишком много запросов к PMS — подождите минуту и повторите.'),
            default => throw new PmsException('PMS вернула ошибку '.$response->status().' — бронь не подтверждена и не сохранена.'),
        };
    }

    private function request(): PendingRequest
    {
        return Http::withToken((string) $this->settings->token)->acceptJson()->timeout(8)->connectTimeout(4);
    }
}
