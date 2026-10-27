<?php

namespace App\Services;

use App\Enums\HistoryEvent;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Exceptions\DomainRuleViolation;
use App\Models\Certificate;
use App\Models\Payment;
use App\Models\YespayWebhookEvent;
use App\Services\KaspiYespay\KaspiYespayClient;
use App\Services\KaspiYespay\KaspiYespayException;
use App\Support\Money;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Счёт Kaspi Pay на телефон заказчика через посредника (вариант «Б»: счёт выставляет сотрудник по черновику).
 * Правила: платёж оплачен только по событию payment.paid с совпавшей суммой, подтверждённому чтением платежа из API, либо по
 * самому чтению из API (сверка по расписанию, кнопка); возврат клиента на сайт ничего не значит. Сумма считается на сервере.
 * Статус «неизвестно» — не отказ и не повод вернуть деньги: платёж помечается «нужна проверка», человеку уходит письмо.
 */
class KaspiYespayPayments
{
    public const PAID = 'paid';

    public const PENDING = 'pending';

    public const REVIEW = 'review';

    /** Финальные неуспешные статусы посредника. */
    private const FAILED = ['failed', 'expired', 'cancelled', 'refunded'];

    public function __construct(
        private readonly KaspiYespayClient $client,
        private readonly PaymentManager $payments,
        private readonly CertificateHistoryRecorder $history,
    ) {}

    public function enabled(): bool
    {
        return KaspiYespayClient::configured();
    }

    /** Выставить счёт на весь неоплаченный остаток: клиент получит уведомление в приложении Kaspi на телефон заказчика. */
    public function createInvoice(Certificate $certificate): Payment
    {
        if (! $this->enabled()) {
            throw new DomainRuleViolation(__('Счета Kaspi не настроены: нужны KASPI_YESPAY_ENABLED, YESPAY_SECRET_KEY и YESPAY_WEBHOOK_SECRET в настройках сервера.'));
        }
        $certificate->loadMissing('customer');
        $phone = (string) $certificate->customer?->phone;
        if (! preg_match('/^\+7\d{10}$/', $phone)) {
            throw new DomainRuleViolation(__('Для счёта в Kaspi у заказчика должен быть казахстанский мобильный номер (+7…). Выставьте счёт другим способом.'));
        }

        $payment = $this->payments->invoiceByGateway($certificate, PaymentMethod::KaspiYespay);
        $payment->update([
            'invoice_number' => 'K'.str_pad((string) $payment->id, 9, '0', STR_PAD_LEFT),
            'kaspi_yespay_idempotency_key' => 'K'.str_pad((string) $payment->id, 9, '0', STR_PAD_LEFT),
            'pay_expires_at' => now()->addMinutes((int) config('gifter.kaspi_yespay.expire_minutes')),
        ]);

        try {
            $created = $this->client->createPayment($payment, $phone, __('Сертификат :p1', ['p1' => $certificate->number]));
        } catch (KaspiYespayException $e) {
            $this->payments->cancel($payment, __('Счёт в Kaspi не создан'));
            if ($e->needsReauth()) {
                $this->alertReauth();
            }
            throw new DomainRuleViolation($e->getMessage());
        }
        if ($created['id'] === '') {
            $this->payments->cancel($payment, __('Счёт в Kaspi не создан'));
            throw new DomainRuleViolation(__('Сервис счетов Kaspi не вернул номер платежа. Попробуйте позже.'));
        }
        $payment->update(['kaspi_yespay_payment_id' => $created['id'], 'kaspi_yespay_status' => $created['status']]);
        $this->history->record($certificate, HistoryEvent::PaymentLinkSent, __('Счёт Kaspi отправлен клиенту на телефон').' · '.Money::format($payment->amount));

        return $payment->refresh();
    }

    /**
     * Приём уведомления посредника (маршрут без JSON-разбора: нужно СЫРОЕ тело). Возвращает HTTP-код.
     * 400 — подпись неверна или событие старше 5 минут (ничего не выполняется); 200 — принято (в том числе повтор
     * уже обработанного события и неизвестный тип). Работа идёт в очереди, ответ быстрее 10 секунд.
     *
     * @param  array<string, string|null>  $headers  signature, event_id
     */
    public function receive(string $rawBody, array $headers): int
    {
        if (! $this->validSignature($rawBody, (string) ($headers['signature'] ?? ''))) {
            Log::warning('Kaspi (счета): уведомление с неверной подписью или старше 5 минут');

            return 400;
        }
        $eventId = (string) ($headers['event_id'] ?? '') ?: 'sha256:'.hash('sha256', $rawBody);
        $type = (string) (((array) json_decode($rawBody, true))['type'] ?? '');
        try {
            $event = YespayWebhookEvent::create(['event_id' => $eventId, 'type' => mb_substr($type, 0, 64), 'received_at' => now()]);
        } catch (UniqueConstraintViolationException) {
            $event = YespayWebhookEvent::query()->where('event_id', $eventId)->firstOrFail();
            if ($event->processed_at) {
                return 200;
            }
        }
        dispatch(function () use ($event, $rawBody) {
            try {
                $this->processEvent($event->id, $rawBody);
            } catch (\Throwable $e) {
                Log::warning('Kaspi (счета): уведомление не обработано, платёж проверит расписание', ['причина' => $e::class, 'сообщение' => $e->getMessage()]);
            }
        })->afterResponse();

        return 200;
    }

    /** Обработать принятое событие ровно один раз (повторная доставка и параллельная обработка отсекаются). */
    public function processEvent(int $eventRowId, string $rawBody): void
    {
        if (YespayWebhookEvent::query()->whereKey($eventRowId)->whereNull('processed_at')->update(['processed_at' => now()]) !== 1) {
            return;
        }
        try {
            $this->dispatchEvent((array) json_decode($rawBody, true));
        } catch (\Throwable $e) {
            YespayWebhookEvent::query()->whereKey($eventRowId)->update(['processed_at' => null]); // даст обработать повторную доставку
            throw $e;
        }
    }

    /** @param  array<string, mixed>  $body */
    private function dispatchEvent(array $body): void
    {
        $data = (array) ($body['data'] ?? []);
        $type = (string) ($body['type'] ?? '');
        if ($type === 'payment_account.reauth_required') {
            $this->alertReauth();

            return;
        }
        if (! in_array($type, ['payment.paid', 'payment.failed', 'payment.expired', 'payment.cancelled', 'payment.status_unknown', 'refund.succeeded'], true)) {
            return; // неизвестный тип — игнорируем, ответ уже 200
        }
        $payment = $this->findPayment($data);
        if (! $payment) {
            Log::warning('Kaspi (счета): уведомление по неизвестному платежу', ['тип' => $type]);

            return;
        }
        match ($type) {
            'payment.paid' => $this->onPaid($payment, $data),
            'payment.status_unknown' => $this->markNeedsReview($payment, 'unknown'),
            'refund.succeeded' => $this->onRefund($payment),
            default => $this->onFinalFailure($payment, substr($type, 8)),
        };
    }

    /** @param  array<string, mixed>  $data */
    private function findPayment(array $data): ?Payment
    {
        $id = (string) ($data['payment_id'] ?? $data['id'] ?? '');
        $order = (string) ($data['order_id'] ?? '');

        return Payment::query()->where('method', PaymentMethod::KaspiYespay)
            ->where(fn ($q) => $q->where('kaspi_yespay_payment_id', $id !== '' ? $id : '~')->orWhere('invoice_number', $order !== '' ? $order : '~'))->first();
    }

    /** payment.paid: сверка суммы события с нашим счётом, затем подтверждение чтением платежа из API (check → settle). @param  array<string, mixed>  $data */
    private function onPaid(Payment $payment, array $data): void
    {
        if (isset($data['amount']) && is_numeric($data['amount']) && (int) $data['amount'] * 100 !== Money::toMinor($payment->amount)) {
            $this->flag($payment, __('Kaspi сообщил об оплате, но сумма события не совпала со счётом — нужна ручная проверка.'), 'сумма оплаты не совпала со счётом');

            return;
        }
        $this->check($payment);
    }

    private function onFinalFailure(Payment $payment, string $status): void
    {
        $payment->update(['kaspi_yespay_status' => $status, 'kaspi_yespay_checked_at' => now()]);
        $this->note($payment, __('Состояние счёта в Kaspi: :p1.', ['p1' => $status]));
        if ($payment->status !== PaymentStatus::Pending) {
            return;
        }
        try {
            $this->payments->cancel($payment, __('Счёт в Kaspi: :p1', ['p1' => $status])); // beforeCancel сверится с API: оплаченный или неизвестный не отменяется
        } catch (DomainRuleViolation $e) {
            $this->flag($payment, __('Событие Kaspi «:p1» противоречит данным сервиса — нужна ручная проверка.', ['p1' => $status]), 'противоречивые данные об оплате');
        }
    }

    private function onRefund(Payment $payment): void
    {
        $this->note($payment, __('В Kaspi выполнен возврат по этому платежу. Отметьте возврат в разделе «Возвраты».'));
        app(PaymentAlert::class)->sendRussian('в Kaspi выполнен возврат', 'Платёж :p1: в Kaspi выполнен возврат. Отметьте возврат в Gifter (раздел «Возвраты»).', ['p1' => $payment->invoice_number]);
    }

    /** «Нужна проверка»: платёж остаётся ожидающим, не отменяется и не оплачивается; человеку уходит письмо (один раз). */
    private function markNeedsReview(Payment $payment, string $status): void
    {
        $payment->update(['kaspi_yespay_status' => $status, 'kaspi_yespay_checked_at' => now()]);
        $this->flag($payment, __('Результат оплаты в Kaspi неизвестен — ничего не меняем, проверим позже. Не считайте это отказом.'), 'результат оплаты неизвестен');
    }

    private function flag(Payment $payment, string $note, string $reason): void
    {
        $first = $payment->needs_review_at === null;
        $payment->update(['needs_review_at' => $payment->needs_review_at ?? now()]);
        $this->note($payment, $note);
        if ($first) {
            app(PaymentAlert::class)->sendRussian('платёж Kaspi требует проверки', 'Платёж :p1 на :p2: :p3. Деньги не возвращайте и сертификат не выпускайте, пока не выяснено.', ['p1' => $payment->invoice_number, 'p2' => Money::format($payment->amount), 'p3' => $reason]);
        }
    }

    /** Кассир Kaspi отключился: письмо ответственному (не чаще раза в 30 минут). */
    private function alertReauth(): void
    {
        if (Cache::add('kaspi-yespay:reauth-alert', 1, now()->addMinutes(30))) {
            app(PaymentAlert::class)->sendRussian('кассир Kaspi отключился', 'Сервис yespay сообщает: кассир Kaspi Pay отключился. Счета не выставляются, платежи могут не подтверждаться, пока кто-то не войдёт заново в кабинете yespay.');
        }
    }

    public function validSignature(string $rawBody, string $header): bool
    {
        $secret = (string) config('gifter.kaspi_yespay.webhook_secret');
        if ($secret === '' || ! preg_match('/t=(\d+)/', $header, $t) || ! preg_match('/v1=([0-9a-f]{64})/i', $header, $v)) {
            return false;
        }
        if (abs(time() - (int) $t[1]) > 300) {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $t[1].'.'.$rawBody, $secret), strtolower($v[1]));
    }

    /** Проверить платёж чтением из API и, если он оплачен, провести. Безопасно вызывать сколько угодно раз. */
    public function check(Payment $payment): string
    {
        $payment->refresh();
        if ($payment->method !== PaymentMethod::KaspiYespay || blank($payment->kaspi_yespay_payment_id)) {
            return self::PENDING;
        }
        $status = $this->client->getPayment($payment->kaspi_yespay_payment_id);
        $previous = $payment->kaspi_yespay_status;
        $payment->update(['kaspi_yespay_checked_at' => now(), 'kaspi_yespay_status' => $status['status']]);

        if ($status['status'] === 'paid') {
            return $this->settle($payment, $status);
        }
        if ($status['status'] === 'unknown') {
            $this->markNeedsReview($payment, 'unknown');
        } elseif ($status['status'] !== $previous && in_array($status['status'], self::FAILED, true)) {
            $this->note($payment, __('Состояние счёта в Kaspi: :p1.', ['p1' => $status['status']]));
        }

        return self::PENDING;
    }

    public function checkNow(Payment $payment): string
    {
        try {
            return $this->check($payment);
        } catch (KaspiYespayException $e) {
            throw new DomainRuleViolation($e->getMessage());
        }
    }

    /** Ожидающие платежи, по которым вебхук мог не дойти, и просроченные счета (расписание). @return array{paid: int, expired: int} */
    public function reconcilePending(): array
    {
        $paid = $expired = 0;
        Payment::query()->where('method', PaymentMethod::KaspiYespay)->where('status', PaymentStatus::Pending)->whereNotNull('kaspi_yespay_payment_id')
            ->where('invoiced_at', '<=', now()->subMinutes(10))
            ->where(fn ($q) => $q->whereNull('kaspi_yespay_checked_at')->orWhere('kaspi_yespay_checked_at', '<=', now()->subMinutes(10)))
            ->orderBy('id')->each(function (Payment $payment) use (&$paid, &$expired) {
                try {
                    if ($this->check($payment) === self::PAID) {
                        $paid++;

                        return;
                    }
                    if ($payment->refresh()->status === PaymentStatus::Pending && $payment->pay_expires_at?->isPast() && $payment->kaspi_yespay_status !== 'unknown') {
                        $this->payments->cancel($payment, __('Счёт в Kaspi истёк'));
                        $expired++;
                    }
                } catch (KaspiYespayException $e) {
                    Log::warning('Kaspi (счета): сверка платежа не удалась', ['payment' => $payment->id, 'причина' => $e->getMessage()]);
                    if ($e->needsReauth()) {
                        $this->alertReauth();
                    }
                } catch (DomainRuleViolation $e) {
                    Log::warning('Kaspi (счета): сверка платежа не удалась', ['payment' => $payment->id, 'причина' => $e->getMessage()]);
                }
            });

        return ['paid' => $paid, 'expired' => $expired];
    }

    /**
     * Перед отменой: оплаченный или «неизвестный» счёт отменять нельзя; неоплаченный отключаем у посредника, чтобы клиент
     * не оплатил отменённое; уже закрытый (failed/expired/cancelled) — отключать нечего.
     */
    public function beforeCancel(Payment $payment): void
    {
        if ($payment->status !== PaymentStatus::Pending || blank($payment->kaspi_yespay_payment_id)) {
            return;
        }
        try {
            $remote = $this->client->getPayment($payment->kaspi_yespay_payment_id);
            $payment->update(['kaspi_yespay_checked_at' => now(), 'kaspi_yespay_status' => $remote['status']]);
            if (in_array($remote['status'], ['paid', 'unknown'], true)) {
                throw new DomainRuleViolation(__('Оплата уже поступила или её результат неизвестен — отменить нельзя, проверьте позже.'));
            }
            if (! in_array($remote['status'], self::FAILED, true)) {
                $this->client->cancel($payment->kaspi_yespay_payment_id);
            }
        } catch (KaspiYespayException $e) {
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
            $this->flag($payment, __('Kaspi сообщил об оплате, но сумма или валюта не совпали со счётом — нужна ручная проверка.'), 'сумма или валюта оплаты не совпали со счётом');
            Log::error('Kaspi (счета): данные оплаты не совпали со счётом', ['payment' => $payment->id]);

            return self::REVIEW;
        }
        if ($payment->status === PaymentStatus::Cancelled) {
            $this->flag($payment, __('Оплата поступила по уже отменённому счёту — нужна ручная проверка и, возможно, возврат.'), 'оплата по отменённому счёту');
            Log::error('Kaspi (счета): оплата по отменённому счёту', ['payment' => $payment->id]);

            return self::REVIEW;
        }
        if ($payment->status === PaymentStatus::Pending) {
            try {
                $this->payments->markPaidByGateway($payment);
                $payment->update(['needs_review_at' => null]);
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
