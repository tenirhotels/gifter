<?php

namespace App\Services;

use App\Enums\OnlineOrderStatus;
use App\Exceptions\DomainRuleViolation;
use App\Models\Certificate;
use App\Models\Customer;
use App\Models\OnlineOrder;
use App\Models\Recipient;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Обработка онлайн-заявок: «Оформить» создаёт (или находит) заказчика и получателя и черновик сертификата
 * от имени сотрудника — он становится продавцом, дальше счёт Kaspi/Halyk и выпуск идут обычным путём.
 * «Отклонить» закрывает заявку с причиной. Заявки не удаляются.
 */
final class OnlineOrderManager
{
    public function __construct(private readonly CertificateManager $certificates) {}

    public function process(OnlineOrder $order, User $by): Certificate
    {
        return DB::transaction(function () use ($order, $by) {
            $fresh = OnlineOrder::query()->lockForUpdate()->findOrFail($order->id);
            $this->assertNew($fresh);

            $customer = Customer::query()->whereContact((string) $fresh->phone)->first()
                ?? Customer::create(['type' => 'individual', 'last_name' => $fresh->last_name, 'first_name' => $fresh->first_name,
                    'phone' => $fresh->phone, 'email' => $fresh->email]);
            $recipient = $this->recipient($fresh);

            $certificate = $this->actingAs($by, fn () => $this->certificates->createDraft([
                'package_id' => $fresh->package_id,
                'customer_id' => $customer->id,
                'recipient_id' => $recipient?->id,
                'notes' => $this->notes($fresh),
            ]));

            $fresh->update(['status' => OnlineOrderStatus::Processed, 'certificate_id' => $certificate->id, 'handled_by' => $by->id, 'handled_at' => now()]);

            return $certificate;
        });
    }

    public function reject(OnlineOrder $order, User $by, string $reason): OnlineOrder
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 5) {
            throw new DomainRuleViolation(__('Укажите причину отказа.'));
        }

        return DB::transaction(function () use ($order, $by, $reason) {
            $fresh = OnlineOrder::query()->lockForUpdate()->findOrFail($order->id);
            $this->assertNew($fresh);
            $fresh->update(['status' => OnlineOrderStatus::Rejected, 'reject_reason' => $reason, 'handled_by' => $by->id, 'handled_at' => now()]);

            return $fresh;
        });
    }

    /** Примечание к черновику: номер заявки, способ получения, адрес, поздравление и пожелания клиента. */
    private function notes(OnlineOrder $order): string
    {
        return collect([
            __('Заявка с сайта № :p1', ['p1' => $order->id]),
            __('Получение: :p1', ['p1' => $order->delivery_method->getLabel()]),
            $order->delivery_address ? __('Адрес доставки — в заявке с сайта (контакты открываются с записью в журнал).') : null,
            $order->gift_message ? __('Поздравление: :p1', ['p1' => $order->gift_message]) : null,
            $order->notes ? __('Пожелания: :p1', ['p1' => $order->notes]) : null,
        ])->filter()->implode("\n");
    }

    /** Черновик создаётся от имени сотрудника: он автор оформления и продавец. */
    private function actingAs(User $user, \Closure $callback): Certificate
    {
        $previous = auth()->user();
        auth()->setUser($user);

        try {
            return $callback();
        } finally {
            $previous ? auth()->setUser($previous) : auth()->forgetUser();
        }
    }

    private function assertNew(OnlineOrder $order): void
    {
        if ($order->status !== OnlineOrderStatus::New) {
            throw new DomainRuleViolation(__('Заявка уже рассмотрена.'));
        }
    }

    /** Получатель из свободного текста «Фамилия Имя»: первое слово — фамилия, остальное — имя. */
    private function recipient(OnlineOrder $order): ?Recipient
    {
        $name = trim((string) $order->recipient_name);
        if ($name === '') {
            return null;
        }
        $parts = preg_split('/\s+/u', $name, 2);

        return Recipient::create(['last_name' => $parts[0], 'first_name' => $parts[1] ?? '']);
    }
}
