<?php

namespace App\Services\KaspiYespay;

use App\Models\Payment;
use App\Support\Money;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Обмен с посредником по приёму Kaspi Pay (yespay). Только транспорт: бизнес-правила — в KaspiYespayPayments.
 * Ключ только в заголовке, в журнал не попадают ни ключи, ни телефоны; TLS проверяется.
 * Повторы — только по кодам 429/502/503 и с тем же Idempotency-Key.
 */
class KaspiYespayClient
{
    public static function configured(): bool
    {
        return (bool) config('gifter.kaspi_yespay.enabled') && filled(config('gifter.kaspi_yespay.api_key')) && filled(config('gifter.kaspi_yespay.webhook_secret'))
            && str_starts_with((string) config('gifter.kaspi_yespay.base_url'), 'https://');
    }

    /** Ключ песочницы (sk_test_…): платежи не настоящие. */
    public static function sandbox(): bool
    {
        return str_starts_with((string) config('gifter.kaspi_yespay.api_key'), 'sk_test_');
    }

    /**
     * Создать платёж: сумма — целые тенге, телефон покупателя — +7XXXXXXXXXX.
     *
     * @return array{id: string, status: string}
     */
    public function createPayment(Payment $payment, string $phone, string $description): array
    {
        $minor = Money::toMinor($payment->amount);
        if ($minor % 100 !== 0) {
            throw new KaspiYespayException(__('Kaspi принимает только целые тенге: сумма :p1 не подходит.', ['p1' => Money::format($payment->amount)]));
        }
        $response = $this->send(fn (PendingRequest $http) => $http->withHeaders(['Idempotency-Key' => (string) $payment->kaspi_yespay_idempotency_key])->post($this->url('/v1/payments'), [
            'amount' => intdiv($minor, 100),
            'currency' => 'KZT',
            'customer' => ['phone' => $phone],
            'order_id' => (string) $payment->invoice_number,
            'description' => mb_substr($description, 0, 120),
        ]), 'создание счёта');

        return $this->normalize((array) $response->json());
    }

    /** @return array{id: string, status: string, amount: ?int, currency: ?string, order_id: ?string} */
    public function getPayment(string $id): array
    {
        $response = $this->send(fn (PendingRequest $http) => $http->get($this->url('/v1/payments/'.rawurlencode($id))), 'проверка статуса');

        return $this->normalize((array) $response->json());
    }

    public function cancel(string $id): void
    {
        $this->send(fn (PendingRequest $http) => $http->post($this->url('/v1/payments/'.rawurlencode($id).'/cancel')), 'отмена счёта');
    }

    /** Проверка настройки без секретов. @return array{sandbox: bool, reachable: bool} */
    public function diagnose(): array
    {
        $this->send(fn (PendingRequest $http) => $http->get($this->url('/v1/payments'), ['order_id' => 'gifter-check']), 'проверка связи');

        return ['sandbox' => self::sandbox(), 'reachable' => true];
    }

    /** Поддерживает ответ и «плоский», и обёрнутый в data. @param  array<string, mixed>  $json @return array{id: string, status: string, amount: ?int, currency: ?string, order_id: ?string} */
    private function normalize(array $json): array
    {
        $data = is_array($json['data'] ?? null) ? $json['data'] : $json;

        return ['id' => (string) ($data['id'] ?? ''), 'status' => strtolower((string) ($data['status'] ?? 'unknown')),
            'amount' => isset($data['amount']) && is_numeric($data['amount']) ? (int) $data['amount'] : null,
            'currency' => isset($data['currency']) ? (string) $data['currency'] : null,
            'order_id' => isset($data['order_id']) ? (string) $data['order_id'] : null];
    }

    private function send(callable $call, string $what): Response
    {
        if (! self::configured()) {
            throw new KaspiYespayException(__('Счета Kaspi не настроены: нужны KASPI_YESPAY_ENABLED, KASPI_YESPAY_API_KEY и KASPI_YESPAY_WEBHOOK_SECRET в настройках сервера.'));
        }
        try {
            $http = Http::timeout(15)->connectTimeout(5)->withToken((string) config('gifter.kaspi_yespay.api_key'))->acceptJson()->asJson()
                ->retry(3, 500, fn ($e) => $e instanceof RequestException && in_array($e->response->status(), [429, 502, 503], true), throw: false);
            $response = $call($http);
        } catch (ConnectionException $e) {
            Log::warning('Kaspi (счета): нет связи', ['операция' => $what, 'причина' => $e::class]);
            throw new KaspiYespayException(__('Сервис счетов Kaspi не отвечает. Попробуйте позже.'));
        }
        if ($response->failed()) {
            $code = $response->json('error.code') ?? $response->json('code') ?? $response->json('error');
            $code = is_string($code) ? $code : null;
            Log::warning('Kaspi (счета): отказ', ['операция' => $what, 'http' => $response->status(), 'код' => $code]);
            if ($code === KaspiYespayException::REAUTH) {
                throw new KaspiYespayException(__('Кассир Kaspi отключился от сервиса счетов — нужен повторный вход в кабинете посредника.'), $code);
            }

            throw new KaspiYespayException(__('Сервис счетов Kaspi отклонил запрос (:p1).', ['p1' => $response->status()]).($code ? ' '.$code : ''), $code);
        }

        return $response;
    }

    private function url(string $path): string
    {
        return rtrim((string) config('gifter.kaspi_yespay.base_url'), '/').$path;
    }
}
