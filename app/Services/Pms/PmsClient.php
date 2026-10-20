<?php

namespace App\Services\Pms;

use App\Models\PmsSetting;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

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
    private const NOT_FOUND = 404;

    private const BAD_NUMBER = 400;

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
     * Проверка связи и ключей: запрос заведомо несуществующей брони в формате номеров PMS
     * (`ГГГГММДД-код отеля-N`). «Не найдена» (или «номер не принят» — ответ дан после проверки токена) — связь и ключи в порядке.
     *
     * @return array{ok: bool, message: string}
     */
    public function ping(): array
    {
        try {
            $this->fetch('20000101-'.$this->settings->property_code.'-1');

            return ['ok' => true, 'message' => __('Связь есть.')];
        } catch (PmsException $e) {
            $reached = in_array($e->getCode(), [self::NOT_FOUND, self::BAD_NUMBER], true);

            return $reached
                ? ['ok' => true, 'message' => __('Связь есть, ключи приняты (тестовая бронь не найдена — так и должно быть).').$this->contentNote()]
                : ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    /** Для Exely: доступен ли справочник услуг (Content API) — отдельное право ключей. */
    private function contentNote(): string
    {
        if (! $this->settings->isExely()) {
            return '';
        }
        try {
            $this->catalogServices();

            return ' '.__('Список услуг (Content API) доступен.');
        } catch (PmsException $e) {
            return ' '.__('Список услуг: :p1', ['p1' => $e->getMessage()]);
        }
    }

    /** Краткая причина сбоя соединения — без адреса с параметрами и без секретов. */
    private function connectionReason(ConnectionException $e, string $url): string
    {
        $host = (string) parse_url($url, PHP_URL_HOST);
        $text = $e->getMessage();
        $reason = match (true) {
            (bool) preg_match('/timed out|cURL error 28|timeout/i', $text) => __('таймаут соединения'),
            (bool) preg_match('/resolve host|cURL error 6\b/i', $text) => __('не удалось определить адрес (DNS)'),
            (bool) preg_match('/SSL|certificate|cURL error (35|51|58|60)\b/i', $text) => __('ошибка сертификата SSL'),
            (bool) preg_match('/refused|cURL error 7\b/i', $text) => __('соединение отклонено'),
            (bool) preg_match('/cURL error (\d+)/i', $text, $m) => __('сетевая ошибка cURL :p1', ['p1' => $m[1]]),
            default => __('сетевая ошибка'),
        };
        Log::warning('PMS: нет соединения', ['host' => $host, 'reason' => $reason]);

        return "{$reason}: {$host}";
    }

    /** Номер как в PMS: без пробелов, невидимых знаков и «умных» тире из копирования. */
    public static function normalizeNumber(string $number): string
    {
        $number = preg_replace('/[\p{Z}\s\x{200B}-\x{200D}\x{FEFF}]+/u', '', $number);

        return preg_replace('/[\x{2010}-\x{2015}\x{2212}]/u', '-', (string) $number);
    }

    /** Формат номеров PMS — `ГГГГММДД-код отеля-N`; проверяется до запроса, чтобы не слать заведомо неверное в PMS. */
    public function formatError(string $number): ?string
    {
        if (! preg_match('/^(\d{8})-(\d+)-(\d+)$/', $number, $m)) {
            return __('Номер брони «:p1» не похож на номер PMS. Формат: ГГГГММДД-код отеля-номер, например :p2.', ['p1' => $number, 'p2' => '20261019-'.($this->settings->property_code ?: '9305').'-1266607523']);
        }
        if (! checkdate((int) substr($m[1], 4, 2), (int) substr($m[1], 6, 2), (int) substr($m[1], 0, 4))) {
            return __('В номере брони «:p1» неверная дата (:p2).', ['p1' => $number, 'p2' => $m[1]]);
        }
        $code = trim((string) $this->settings->property_code);
        if ($code !== '' && $m[2] !== $code) {
            return __('Номер брони «:p1» относится к другому отелю (код :p2, в настройках — :p3).', ['p1' => $number, 'p2' => $m[2], 'p3' => $code]);
        }

        return null;
    }

    /**
     * Список услуг отеля из справочника Exely (Content API: `GET /api/content/v1/properties/{propertyId}`, поле `services`).
     * Берутся только услуги (ID, название, вид, статус); тарифы и цены не читаются. Только прямой Exely — хаб пока не подключён.
     *
     * @return list<array{id: string, name: string, kind: ?string, status: ?string}>
     */
    public function catalogServices(): array
    {
        if (! $this->isActive()) {
            throw new PmsException(__('Интеграция с PMS не настроена.'));
        }
        if (! $this->settings->isExely()) {
            throw new PmsException(__('Список услуг сейчас берётся только из Exely напрямую: выберите источник «Exely напрямую» в настройках PMS.'));
        }
        $code = rawurlencode((string) $this->settings->property_code);
        $url = $this->settings->baseUrl()."/api/content/v1/properties/{$code}";
        try {
            $response = $this->request(20)->get($url);
            if ($response->status() === 401) {
                Cache::forget($this->tokenKey());
                $response = $this->request(20)->get($url);
            }
        } catch (ConnectionException $e) {
            throw new PmsException(__('Exely не отвечает (:p1) — список услуг не обновлён. Попробуйте позже.', ['p1' => $this->connectionReason($e, $url)]));
        }
        if (in_array($response->status(), [401, 403], true)) {
            throw new PmsException(__('Exely не пускает Gifter в Content API (нет доступа у ключей) — запросите доступ к Content API у Exely.'));
        }
        if ($response->status() === 429) {
            throw new PmsException(__('Слишком много запросов к Exely — подождите минуту и повторите.'));
        }
        if (! $response->successful()) {
            throw new PmsException(__('Exely вернул ошибку :p1 — список услуг не обновлён.', ['p1' => $response->status()]));
        }

        return collect((array) $response->json('services', []))
            ->filter(fn ($s) => is_array($s) && filled($s['id'] ?? null) && filled($s['name'] ?? null))
            ->map(fn (array $s) => ['id' => (string) $s['id'], 'name' => trim((string) $s['name']), 'kind' => isset($s['kind']) ? (string) $s['kind'] : null, 'status' => isset($s['status']) ? (string) $s['status'] : null])
            ->unique('id')->values()->all();
    }

    private function fetch(string $number): PmsBooking
    {
        $number = self::normalizeNumber($number);
        if (! $this->isActive()) {
            throw new PmsException(__('Интеграция с PMS не настроена.'));
        }
        if ($number === '') {
            throw new PmsException(__('Укажите номер брони PMS.'));
        }
        if ($error = $this->formatError($number)) {
            throw new PmsException($error);
        }

        $response = $this->get($number);
        // Токен Exely живёт 15 минут: если он протух раньше срока — один раз получаем новый.
        if ($response->status() === 401 && $this->settings->isExely()) {
            Cache::forget($this->tokenKey());
            $response = $this->get($number);
        }

        return match (true) {
            $response->successful() => PmsBooking::fromResponse((array) $response->json(), $number),
            $response->status() === 404 => throw new PmsException(__('Бронь № :p1 не найдена в PMS. Проверьте номер.', ['p1' => $number]), self::NOT_FOUND),
            $response->status() === 503 => throw new PmsException(__('Данные PMS устарели (нет связи с Exely) — бронь не подтверждена и не сохранена. Попробуйте позже.')),
            $response->status() === 400 => throw new PmsException(__('Номер брони «:p1» PMS не принимает — проверьте формат.', ['p1' => $number]), self::BAD_NUMBER),
            in_array($response->status(), [401, 403], true) => throw new PmsException($this->settings->isExely()
                ? __('Exely не пускает Gifter в Read Reservation API (нет доступа или неверные ключи) — обратитесь к администратору.')
                : __('PMS отклонила токен Gifter (нет права «bookings» или он неверен) — обратитесь к администратору.')),
            $response->status() === 429 => throw new PmsException(__('Слишком много запросов к PMS — подождите минуту и повторите.')),
            default => throw new PmsException(__('PMS вернула ошибку :p1 — бронь не подтверждена и не сохранена.', ['p1' => $response->status()])),
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
        } catch (ConnectionException $e) {
            throw new PmsException(__('PMS не отвечает (:p1) — бронь не подтверждена и не сохранена. Попробуйте позже.', ['p1' => $this->connectionReason($e, $this->settings->baseUrl().$path)]));
        }
    }

    private function request(int $timeout = 8): PendingRequest
    {
        $token = $this->settings->isExely() ? $this->exelyToken() : (string) $this->settings->token;

        return Http::withToken($token)->acceptJson()->timeout($timeout)->connectTimeout(10)->retry(2, 400, fn (\Throwable $e) => $e instanceof ConnectionException, false);
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
            $response = Http::asForm()->acceptJson()->timeout(15)->connectTimeout(10)->retry(2, 400, fn (\Throwable $e) => $e instanceof ConnectionException, false)->post($this->settings->baseUrl().'/auth/token', [
                'grant_type' => 'client_credentials',
                'client_id' => $this->settings->client_id,
                'client_secret' => $this->settings->client_secret,
            ]);
        } catch (ConnectionException $e) {
            $host = (string) parse_url($this->settings->baseUrl(), PHP_URL_HOST);
            throw new PmsException(__('Exely не отвечает (:p1): Gifter не смог получить токен. Если повторяется — проверьте адрес в настройках и исходящий доступ хостинга к :p2:443.', ['p1' => $this->connectionReason($e, $this->settings->baseUrl().'/auth/token'), 'p2' => $host]));
        }
        if (in_array($response->status(), [400, 401, 403], true)) {
            throw new PmsException(__('Exely отклонил ключи Gifter (client_id / client_secret) — обратитесь к администратору.'));
        }
        if ($response->status() === 429) {
            throw new PmsException(__('Слишком много запросов токена к Exely — подождите минуту и повторите.'));
        }
        $token = (string) $response->json('access_token');
        if (! $response->successful() || $token === '') {
            throw new PmsException(__('Exely не выдал токен (ошибка :p1) — бронь не подтверждена и не сохранена.', ['p1' => $response->status()]));
        }
        Cache::put($this->tokenKey(), $token, max(60, ((int) $response->json('expires_in', 900)) - 60));

        return $token;
    }
}
