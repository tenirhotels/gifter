<?php

namespace App\Services\Epay;

use App\Models\Payment;
use App\Support\Money;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Обмен с банком (эквайринг, API ссылки на оплату). Только транспорт: бизнес-правила — в EpayPayments.
 * Токен берётся заново на каждую операцию (банк привязывает его к операции); TLS всегда проверяется;
 * ключи и токены в журнал не попадают.
 */
class EpayClient
{
    /** Предел описания платежа у банка — 125 байт (кириллица — 2 байта на букву). */
    public const DESCRIPTION_BYTES = 125;

    public static function configured(): bool
    {
        return filled(config('gifter.epay.client_id')) && filled(config('gifter.epay.client_secret')) && filled(config('gifter.epay.terminal'))
            && isset(config('gifter.epay.hosts')[config('gifter.epay.env')]);
    }

    public function terminal(): string
    {
        return (string) config('gifter.epay.terminal');
    }

    /** @return array{id: ?string, url: string, expires: ?string} */
    public function createInvoiceLink(Payment $payment, string $description, string $email, string $phone, string $accountId, string $language): array
    {
        $response = $this->send(fn (PendingRequest $http) => $http->withToken($this->token('payment'))->acceptJson()->asJson()->post($this->api('/invoice'), [
            'shop_id' => $this->terminal(),
            'account_id' => $accountId,
            'invoice_id' => $payment->epay_invoice_id,
            'amount' => Money::toMinor($payment->amount) / 100,
            'language' => $language,
            'description' => self::trimBytes($description, self::DESCRIPTION_BYTES),
            'expire_period' => (int) config('gifter.epay.expire_period').'d',
            'recipient_contact' => $email,
            'recipient_contact_sms' => $phone,
            'currency' => $payment->currency,
            'post_link' => route('epay.postlink'),
            'failure_post_link' => route('epay.postlink'),
            'back_link' => url('/'),
            'failure_back_link' => url('/'),
        ]), 'создание ссылки на оплату');

        $url = (string) ($response->json('invoice_url') ?? '');
        if (! str_starts_with($url, 'https://')) {
            throw new EpayException(__('Банк не вернул ссылку на оплату. Попробуйте позже.'));
        }

        return ['id' => $response->json('id') !== null ? (string) $response->json('id') : null, 'url' => $url, 'expires' => $response->json('expire_date')];
    }

    /**
     * Статус операции по номеру счёта (check-status).
     *
     * @return array{code: string, status: ?string, id: ?string, amount: ?string, currency: ?string, terminal: ?string, reason: ?string}
     */
    public function checkStatus(string $invoiceId): array
    {
        $response = $this->send(fn (PendingRequest $http) => $http->withToken($this->token('payment', withTerminal: true))->acceptJson()
            ->retry(2, 300, throw: false)->get($this->api('/check-status/payment/transaction/'.rawurlencode($invoiceId))), 'проверка статуса оплаты', allowClientError: true);

        $transaction = (array) ($response->json('transaction') ?? []);

        return [
            'code' => (string) ($response->json('resultCode') ?? ''),
            'status' => isset($transaction['statusName']) ? (string) $transaction['statusName'] : null,
            'id' => isset($transaction['id']) ? (string) $transaction['id'] : null,
            'amount' => isset($transaction['amount']) ? Money::fromMinor(Money::toMinor((string) $transaction['amount'])) : null,
            'currency' => isset($transaction['currency']) ? (string) $transaction['currency'] : null,
            'terminal' => isset($transaction['terminalID']) ? (string) $transaction['terminalID'] : null,
            'reason' => isset($transaction['reasonCode']) ? (string) $transaction['reasonCode'] : null,
        ];
    }

    /** Отключить ссылку: оплатить её больше нельзя. */
    public function deactivateLink(string $linkId): void
    {
        $this->send(fn (PendingRequest $http) => $http->withToken($this->token('payment'))->acceptJson()
            ->put($this->api('/deactivate/invoice-links/'.rawurlencode($linkId))), 'отключение ссылки на оплату');
    }

    /** Обрезка строки по байтам UTF-8 без разрыва символа. */
    public static function trimBytes(string $text, int $limit): string
    {
        $text = trim($text);
        if (strlen($text) <= $limit) {
            return $text;
        }
        $cut = substr($text, 0, $limit);
        while ($cut !== '' && ! mb_check_encoding($cut, 'UTF-8')) {
            $cut = substr($cut, 0, -1);
        }

        return rtrim($cut);
    }

    private function token(string $scope, bool $withTerminal = false): string
    {
        $fields = ['grant_type' => 'client_credentials', 'scope' => $scope,
            'client_id' => (string) config('gifter.epay.client_id'), 'client_secret' => (string) config('gifter.epay.client_secret')];
        if ($withTerminal) {
            $fields['terminal'] = $this->terminal();
        }
        $response = $this->send(fn (PendingRequest $http) => $http->asForm()->acceptJson()->post($this->host('oauth').'/oauth2/token', $fields), 'получение токена');
        $token = (string) ($response->json('access_token') ?? '');
        if ($token === '') {
            throw new EpayException(__('Банк не выдал токен доступа. Проверьте ключи оплаты в настройках сервера.'));
        }

        return $token;
    }

    private function send(callable $call, string $what, bool $allowClientError = false): Response
    {
        if (! self::configured()) {
            throw new EpayException(__('Оплата картой не настроена: нужны EPAY_CLIENT_ID, EPAY_CLIENT_SECRET и EPAY_TERMINAL в настройках сервера.'));
        }
        try {
            $response = $call(Http::timeout(15)->connectTimeout(5));
        } catch (ConnectionException $e) {
            Log::warning('Банк (оплата): нет связи', ['операция' => $what, 'причина' => $e::class]);
            throw new EpayException(__('Банк не отвечает. Попробуйте позже.'));
        }
        if ($response->failed() && ! ($allowClientError && $response->clientError())) {
            Log::warning('Банк (оплата): отказ', ['операция' => $what, 'http' => $response->status(), 'ответ' => mb_substr((string) $response->body(), 0, 300)]);
            throw new EpayException(__('Банк отклонил запрос (:p1). Подробности — в журнале сервера.', ['p1' => $response->status()]));
        }

        return $response;
    }

    private function host(string $kind): string
    {
        return rtrim((string) config('gifter.epay.hosts.'.config('gifter.epay.env').'.'.$kind), '/');
    }

    private function api(string $path): string
    {
        return $this->host('api').$path;
    }
}
