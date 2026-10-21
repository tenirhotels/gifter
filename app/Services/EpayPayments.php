<?php

namespace App\Services;

use App\Enums\HistoryEvent;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Exceptions\DomainRuleViolation;
use App\Mail\EpayPaymentLinkMail;
use App\Models\Certificate;
use App\Models\Payment;
use App\Services\Epay\EpayClient;
use App\Services\Epay\EpayException;
use App\Support\ContactMask;
use App\Support\Money;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Оплата картой по ссылке банка (вариант «Б»): сотрудник создаёт ссылку по черновику, клиент платит на странице банка.
 * Банк присылает уведомление (postLink), но ему не доверяем: оплата засчитывается только после прямой проверки
 * статуса в API банка (списано, сумма, валюта и терминал совпали). Обработка идемпотентна.
 */
class EpayPayments
{
    /** Итоги проверки платежа. */
    public const PAID = 'paid';

    public const PENDING = 'pending';

    public const REVIEW = 'review';

    private const FAILED_STATES = ['REJECT', 'FAILED', 'CANCEL', 'CANCEL_OLD', 'REFUND', '3D'];

    public function __construct(
        private readonly EpayClient $client,
        private readonly PaymentManager $payments,
        private readonly CertificateHistoryRecorder $history,
    ) {}

    public function enabled(): bool
    {
        return EpayClient::configured();
    }

    /**
     * Создать платёж на весь неоплаченный остаток, ссылку в банке и отправить её клиенту.
     * Адрес — указанный сотрудником или сохранённый у заказчика (в форме не показывается: контакты открываются по кнопке с аудитом).
     */
    public function createLink(Certificate $certificate, ?string $email = null, ?string $lang = null): Payment
    {
        if (! $this->enabled()) {
            throw new DomainRuleViolation(__('Оплата картой не настроена: нужны EPAY_CLIENT_ID, EPAY_CLIENT_SECRET и EPAY_TERMINAL в настройках сервера.'));
        }
        $certificate->loadMissing(['customer', 'onlineOrder']);
        $email = $this->email($certificate, $email);
        $phone = (string) $certificate->customer?->phone;
        if ($phone === '') {
            throw new DomainRuleViolation(__('Для ссылки на оплату у заказчика должен быть телефон.'));
        }
        $lang = $this->lang($lang, $certificate);

        $payment = $this->payments->invoiceByGateway($certificate);
        $invoiceId = str_pad((string) $payment->id, 9, '0', STR_PAD_LEFT);
        $payment->update([
            'epay_invoice_id' => $invoiceId,
            'invoice_number' => $invoiceId,
            'pay_expires_at' => now()->addDays((int) config('gifter.epay.expire_period')),
        ]);

        try {
            $link = $this->client->createInvoiceLink($payment, __('Сертификат :p1', ['p1' => $certificate->number], $lang), $email, $phone, 'c'.$certificate->customer_id, $this->bankLanguage($lang));
        } catch (EpayException $e) {
            $this->payments->cancel($payment, __('Банк не создал ссылку на оплату'));
            throw new DomainRuleViolation($e->getMessage());
        }
        $payment->update(['pay_url' => $link['url'], 'epay_link_id' => $link['id']]);
        $this->sendLink($payment, $email, $lang);

        return $payment->refresh();
    }

    /** Отправить ссылку ещё раз (по ожидающему платежу). */
    public function resend(Payment $payment, ?string $email = null, ?string $lang = null): void
    {
        $payment->refresh();
        if ($payment->method !== PaymentMethod::HalykEpay || $payment->status !== PaymentStatus::Pending || blank($payment->pay_url)) {
            throw new DomainRuleViolation(__('Ссылку можно отправить только по ожидающей оплате картой.'));
        }
        $certificate = $payment->certificate()->with(['customer', 'onlineOrder'])->firstOrFail();
        $this->sendLink($payment, $this->email($certificate, $email), $this->lang($lang, $certificate));
    }

    /** Уведомление банка (postLink). Из тела берётся только номер счёта — всё остальное проверяется запросом в банк. */
    public function handleCallback(array $body): ?string
    {
        $invoiceId = (string) ($body['invoiceId'] ?? $body['invoice_id'] ?? '');
        if (! preg_match('/^\d{6,15}$/', $invoiceId)) {
            Log::warning('Банк (оплата): уведомление без номера счёта');

            return null;
        }
        $payment = Payment::query()->where('epay_invoice_id', $invoiceId)->first();
        if (! $payment) {
            Log::warning('Банк (оплата): уведомление по неизвестному счёту', ['invoice' => $invoiceId]);

            return null;
        }

        return $this->check($payment);
    }

    /** Проверить платёж в банке и, если деньги списаны, провести оплату. Безопасно вызывать сколько угодно раз. */
    public function check(Payment $payment): string
    {
        $payment->refresh();
        if ($payment->method !== PaymentMethod::HalykEpay || blank($payment->epay_invoice_id)) {
            return self::PENDING;
        }
        $status = $this->client->checkStatus($payment->epay_invoice_id);
        $previous = $payment->epay_status;
        $payment->update(['epay_checked_at' => now(), 'epay_status' => $status['status'] ?? $payment->epay_status,
            'epay_transaction_id' => $status['id'] ?? $payment->epay_transaction_id]);

        // 100 — запрос успешен, смотрим на состояние платежа; остальное (в процессе, не найден) — спросим позже.
        if ($status['code'] !== '100' || $status['status'] === null) {
            return self::PENDING;
        }
        if ($status['status'] === 'CHARGE') {
            return $this->settle($payment, $status);
        }
        if ($status['status'] !== $previous && in_array($status['status'], [...self::FAILED_STATES, 'AUTH'], true)) {
            $this->note($payment, __('Состояние в банке: :p1.', ['p1' => $status['status']]).($status['status'] === 'AUTH' ? ' '.__('Деньги заблокированы, но не списаны — проверьте настройку терминала.') : ''));
        }

        return self::PENDING;
    }

    /** Проверка по кнопке сотрудника: сбой связи с банком — сообщение, а не ошибка сервера. */
    public function checkNow(Payment $payment): string
    {
        try {
            return $this->check($payment);
        } catch (EpayException $e) {
            throw new DomainRuleViolation($e->getMessage());
        }
    }

    /** Платежи, по которым уведомление могло не дойти, и просроченные ссылки (запуск по расписанию). */
    public function reconcilePending(): array
    {
        $paid = $expired = 0;
        Payment::query()->where('method', PaymentMethod::HalykEpay)->where('status', PaymentStatus::Pending)->whereNotNull('epay_invoice_id')
            ->where('invoiced_at', '<=', now()->subMinutes(10))
            ->where(fn ($q) => $q->whereNull('epay_checked_at')->orWhere('epay_checked_at', '<=', now()->subMinutes(10)))
            ->orderBy('id')->each(function (Payment $payment) use (&$paid, &$expired) {
                try {
                    if ($this->check($payment) === self::PAID) {
                        $paid++;

                        return;
                    }
                    if ($payment->refresh()->status === PaymentStatus::Pending && $payment->pay_expires_at?->isPast()) {
                        $this->payments->cancel($payment, __('Ссылка на оплату истекла'));
                        $expired++;
                    }
                } catch (EpayException|DomainRuleViolation $e) {
                    Log::warning('Банк (оплата): сверка платежа не удалась', ['payment' => $payment->id, 'причина' => $e->getMessage()]);
                }
            });

        return ['paid' => $paid, 'expired' => $expired];
    }

    /**
     * Перед отменой платежа: если деньги уже списаны — отменять нельзя; иначе ссылку отключаем в банке,
     * чтобы клиент не оплатил отменённый счёт. Без связи с банком отмена откладывается (кроме истёкшей ссылки).
     */
    public function beforeCancel(Payment $payment): void
    {
        if ($payment->status !== PaymentStatus::Pending || blank($payment->epay_link_id)) {
            return;
        }
        try {
            if ($this->check($payment) !== self::PENDING) {
                throw new DomainRuleViolation(__('Оплата уже поступила или требует проверки — отменить нельзя.'));
            }
            $this->client->deactivateLink($payment->epay_link_id);
        } catch (EpayException $e) {
            if (! $payment->pay_expires_at?->isPast()) {
                throw new DomainRuleViolation(__('Не удалось отключить ссылку в банке: :p1', ['p1' => $e->getMessage()]));
            }
        }
    }

    /** @param  array{amount: ?string, currency: ?string, terminal: ?string, id: ?string}  $status */
    private function settle(Payment $payment, array $status): string
    {
        $matches = $status['terminal'] === $this->client->terminal()
            && $status['currency'] === $payment->currency
            && Money::toMinor($status['amount']) === Money::toMinor($payment->amount);
        if (! $matches) {
            $this->note($payment, __('Банк сообщил об оплате, но сумма, валюта или терминал не совпали со счётом — нужна ручная проверка.'));
            Log::error('Банк (оплата): данные списания не совпали со счётом', ['payment' => $payment->id]);

            return self::REVIEW;
        }
        if ($payment->status === PaymentStatus::Cancelled) {
            $this->note($payment, __('Оплата поступила по уже отменённой ссылке — нужна ручная проверка и, возможно, возврат.'));
            Log::error('Банк (оплата): оплата по отменённой ссылке', ['payment' => $payment->id]);

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

    private function sendLink(Payment $payment, string $email, string $lang): void
    {
        $certificate = $payment->certificate;
        Mail::to($email)->send(new EpayPaymentLinkMail($payment->pay_url, Money::format($payment->amount), $certificate->number, $payment->pay_expires_at->format('d.m.Y'), $lang));
        $this->history->record($certificate, HistoryEvent::PaymentLinkSent, ContactMask::email($email).' · '.strtoupper($lang));
    }

    private function note(Payment $payment, string $text): void
    {
        $this->history->record($payment->certificate, HistoryEvent::GatewayNote, $text);
    }

    private function email(Certificate $certificate, ?string $override): string
    {
        $email = trim((string) ($override ?: ($certificate->onlineOrder?->email ?: $certificate->customer?->email)));
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new DomainRuleViolation(__('Укажите правильный email, куда отправить ссылку.'));
        }

        return $email;
    }

    private function lang(?string $lang, Certificate $certificate): string
    {
        $lang = $lang ?: ($certificate->onlineOrder?->locale ?? app()->getLocale());

        return array_key_exists($lang, config('gifter.locales')) ? $lang : config('gifter.default_locale');
    }

    private function bankLanguage(string $lang): string
    {
        return ['kk' => 'kaz', 'en' => 'eng'][$lang] ?? 'rus';
    }
}
