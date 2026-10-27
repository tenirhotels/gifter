<?php

namespace App\Services;

use App\Enums\HistoryEvent;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Exceptions\DomainRuleViolation;
use App\Models\Certificate;
use App\Models\Payment;
use App\Services\Kaspi\KaspiClient;
use App\Services\Kaspi\KaspiException;
use App\Support\Money;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Счёт Kaspi Pay на телефон заказчика через посредника (вариант «Б»: счёт выставляет сотрудник по черновику).
 * Правила: платёж считается оплаченным только по подтверждению API посредника (вебхук — лишь повод спросить), сумма считается
 * на сервере, статус «неизвестно» — не отказ (ничего не меняем и ждём), возврат клиента на сайт ничего не значит.
 */
class KaspiPayments
{
    public const PAID = 'paid';

    public const PENDING = 'pending';

    public const REVIEW = 'review';

    /** Финальные неуспешные статусы посредника. */
    private const FAILED = ['failed', 'expired', 'cancelled', 'refunded'];

    public function __construct(
        private readonly KaspiClient $client,
        private readonly PaymentManager $payments,
        private readonly CertificateHistoryRecorder $history,
    ) {}

    public function enabled(): bool
    {
        return KaspiClient::configured();
    }

    /** Выставить счёт на весь неоплаченный остаток: клиент получит уведомление в приложении Kaspi на телефон заказчика. */
    public function createInvoice(Certificate $certificate): Payment
    {
        if (! $this->enabled()) {
            throw new DomainRuleViolation(__('Счета Kaspi не настроены: нужны KASPI_ENABLED, KASPI_API_KEY и KASPI_WEBHOOK_SECRET в настройках сервера.'));
        }
        $certificate->loadMissing('customer');
        $phone = (string) $certificate->customer?->phone;
        if (! preg_match('/^\+7\d{10}$/', $phone)) {
            throw new DomainRuleViolation(__('Для счёта в Kaspi у заказчика должен быть казахстанский мобильный номер (+7…). Выставьте счёт другим способом.'));
        }

        $payment = $this->payments->invoiceByGateway($certificate, PaymentMethod::KaspiPay);
        $payment->update([
            'invoice_number' => 'K'.str_pad((string) $payment->id, 9, '0', STR_PAD_LEFT),
            'kaspi_idempotency_key' => (string) Str::uuid(),
            'pay_expires_at' => now()->addMinutes((int) config('gifter.kaspi.expire_minutes')),
        ]);

        try {
            $created = $this->client->createPayment($payment, $phone, __('Сертификат :p1', ['p1' => $certificate->number]));
        } catch (KaspiException $e) {
            $this->payments->cancel($payment, __('Счёт в Kaspi не создан'));
            throw new DomainRuleViolation($e->getMessage());
        }
        if ($created['id'] === '') {
            $this->payments->cancel($payment, __('Счёт в Kaspi не создан'));
            throw new DomainRuleViolation(__('Сервис счетов Kaspi не вернул номер платежа. Попробуйте позже.'));
        }
        $payment->update(['kaspi_payment_id' => $created['id'], 'kaspi_status' => $created['status']]);
        $this->history->record($certificate, HistoryEvent::PaymentLinkSent, __('Счёт Kaspi отправлен клиенту на телефон').' · '.Money::format($payment->amount));

        return $payment->refresh();
    }

    /**
     * Уведомление посредника. Подпись считается по сырому телу (HMAC-SHA256 от "<t>." + тело, ключ — секрет вебхука),
     * возраст события не больше 5 минут, повтор одного события игнорируется. Из тела берутся только идентификаторы —
     * факт оплаты проверяется запросом в API.
     *
     * @param  array<string, string|null>  $headers
     */
    public function handleWebhook(string $rawBody, array $headers): ?string
    {
        if (! $this->validSignature($rawBody, (string) ($headers['signature'] ?? ''))) {
            Log::warning('Kaspi (счета): уведомление с неверной подписью');

            return null;
        }
        $eventId = (string) ($headers['event_id'] ?? '');
        if ($eventId !== '' && ! Cache::add('kaspi:event:'.$eventId, 1, now()->addDay())) {
            return null;
        }
        $body = (array) json_decode($rawBody, true);
        $data = (array) ($body['data'] ?? []);
        $payment = Payment::query()->where('method', PaymentMethod::KaspiPay)
            ->where(fn ($q) => $q->where('kaspi_payment_id', (string) ($data['id'] ?? $data['payment_id'] ?? '~'))->orWhere('invoice_number', (string) ($data['order_id'] ?? '~')))->first();
        if (! $payment) {
            Log::warning('Kaspi (счета): уведомление по неизвестному платежу');

            return null;
        }

        return $this->check($payment);
    }

    public function validSignature(string $rawBody, string $header): bool
    {
        $secret = (string) config('gifter.kaspi.webhook_secret');
        if ($secret === '' || ! preg_match('/t=(\d+)/', $header, $t) || ! preg_match('/v1=([0-9a-f]{64})/i', $header, $v)) {
            return false;
        }
        if (abs(time() - (int) $t[1]) > 300) {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $t[1].'.'.$rawBody, $secret), strtolower($v[1]));
    }

    /** Проверить платёж у посредника и, если он оплачен, провести. Безопасно вызывать сколько угодно раз. */
    public function check(Payment $payment): string
    {
        $payment->refresh();
        if ($payment->method !== PaymentMethod::KaspiPay || blank($payment->kaspi_payment_id)) {
            return self::PENDING;
        }
        $status = $this->client->getPayment($payment->kaspi_payment_id);
        $previous = $payment->kaspi_status;
        $payment->update(['kaspi_checked_at' => now(), 'kaspi_status' => $status['status']]);

        if ($status['status'] === 'paid') {
            return $this->settle($payment, $status);
        }
        if ($status['status'] !== $previous && in_array($status['status'], [...self::FAILED, 'unknown'], true)) {
            $this->note($payment, $status['status'] === 'unknown'
                ? __('Результат оплаты в Kaspi неизвестен — ничего не меняем, проверим позже. Не считайте это отказом.')
                : __('Состояние счёта в Kaspi: :p1.', ['p1' => $status['status']]));
        }

        return self::PENDING;
    }

    public function checkNow(Payment $payment): string
    {
        try {
            return $this->check($payment);
        } catch (KaspiException $e) {
            throw new DomainRuleViolation($e->getMessage());
        }
    }

    /** Ожидающие платежи, по которым вебхук мог не дойти, и просроченные счета (расписание). @return array{paid: int, expired: int} */
    public function reconcilePending(): array
    {
        $paid = $expired = 0;
        Payment::query()->where('method', PaymentMethod::KaspiPay)->where('status', PaymentStatus::Pending)->whereNotNull('kaspi_payment_id')
            ->where('invoiced_at', '<=', now()->subMinutes(10))
            ->where(fn ($q) => $q->whereNull('kaspi_checked_at')->orWhere('kaspi_checked_at', '<=', now()->subMinutes(10)))
            ->orderBy('id')->each(function (Payment $payment) use (&$paid, &$expired) {
                try {
                    if ($this->check($payment) === self::PAID) {
                        $paid++;

                        return;
                    }
                    if ($payment->refresh()->status === PaymentStatus::Pending && $payment->pay_expires_at?->isPast() && $payment->kaspi_status !== 'unknown') {
                        $this->payments->cancel($payment, __('Счёт в Kaspi истёк'));
                        $expired++;
                    }
                } catch (KaspiException $e) {
                    Log::log($e->needsReauth() ? 'critical' : 'warning', 'Kaspi (счета): сверка платежа не удалась', ['payment' => $payment->id, 'причина' => $e->getMessage()]);
                } catch (DomainRuleViolation $e) {
                    Log::warning('Kaspi (счета): сверка платежа не удалась', ['payment' => $payment->id, 'причина' => $e->getMessage()]);
                }
            });

        return ['paid' => $paid, 'expired' => $expired];
    }

    /** Перед отменой: оплаченный счёт отменять нельзя; неоплаченный отключаем у посредника, чтобы клиент не оплатил отменённое. */
    public function beforeCancel(Payment $payment): void
    {
        if ($payment->status !== PaymentStatus::Pending || blank($payment->kaspi_payment_id)) {
            return;
        }
        try {
            if ($this->check($payment) !== self::PENDING || $payment->kaspi_status === 'unknown') {
                throw new DomainRuleViolation(__('Оплата уже поступила или её результат неизвестен — отменить нельзя, проверьте позже.'));
            }
            $this->client->cancel($payment->kaspi_payment_id);
        } catch (KaspiException $e) {
            if (! $payment->pay_expires_at?->isPast()) {
                throw new DomainRuleViolation(__('Не удалось отменить счёт в Kaspi: :p1', ['p1' => $e->getMessage()]));
            }
        }
    }

    /** @param  array{amount: ?int, currency: ?string}  $status */
    private function settle(Payment $payment, array $status): string
    {
        $matches = $status['currency'] === 'KZT' && $status['amount'] !== null && Money::toMinor($payment->amount) === $status['amount'] * 100;
        if (! $matches) {
            $this->note($payment, __('Kaspi сообщил об оплате, но сумма или валюта не совпали со счётом — нужна ручная проверка.'));
            Log::error('Kaspi (счета): данные оплаты не совпали со счётом', ['payment' => $payment->id]);

            return self::REVIEW;
        }
        if ($payment->status === PaymentStatus::Cancelled) {
            $this->note($payment, __('Оплата поступила по уже отменённому счёту — нужна ручная проверка и, возможно, возврат.'));
            Log::error('Kaspi (счета): оплата по отменённому счёту', ['payment' => $payment->id]);

            return self::REVIEW;
        }
        if ($payment->status === PaymentStatus::Pending) {
            try {
                $this->payments->markPaidByGateway($payment);
            } catch (DomainRuleViolation) {
                // Другой запрос уже провёл эту оплату.
            }
        }

        return self::PAID;
    }

    private function note(Payment $payment, string $text): void
    {
        $this->history->record($payment->certificate, HistoryEvent::GatewayNote, $text);
    }
}
