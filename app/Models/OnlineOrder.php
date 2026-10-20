<?php

namespace App\Models;

use App\Enums\DeliveryMethod;
use App\Enums\OnlineOrderStatus;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasEncryptedContacts;
use App\Models\Concerns\HasPersonName;
use App\Models\Concerns\PreventsDeletion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Заявка на сертификат с публичной страницы. Оплаты и выпуска здесь нет: сотрудник оформляет заявку
 * в черновик сертификата и выставляет счёт Kaspi/Halyk обычным путём. Контакты хранятся зашифрованными.
 */
class OnlineOrder extends Model
{
    use Auditable, HasEncryptedContacts, HasPersonName, PreventsDeletion;

    protected $fillable = ['package_id', 'last_name', 'first_name', 'phone', 'email', 'recipient_name', 'delivery_method', 'delivery_address', 'gift_message', 'notes', 'locale', 'status',
        'certificate_id', 'handled_by', 'handled_at', 'reject_reason', 'ip_address'];

    protected $attributes = ['status' => 'new', 'locale' => 'ru', 'delivery_method' => 'pickup'];

    protected function casts(): array
    {
        return ['status' => OnlineOrderStatus::class, 'handled_at' => 'datetime', 'delivery_method' => DeliveryMethod::class, 'delivery_address' => 'encrypted',
            'recipient_name' => 'encrypted', 'gift_message' => 'encrypted'];
    }

    /** В журнал аудита личные данные не попадают: только отметка, что поле заполнено. */
    protected function maskContactsForAudit(array $attributes): array
    {
        foreach ([...self::ENCRYPTED_CONTACTS, 'phone_hash', 'email_hash', 'recipient_name', 'gift_message', 'delivery_address', 'last_name', 'first_name', 'full_name'] as $key) {
            if (array_key_exists($key, $attributes)) {
                $attributes[$key] = $attributes[$key] === null ? null : __('[скрыто]');
            }
        }

        return $attributes;
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(Package::class);
    }

    public function certificate(): BelongsTo
    {
        return $this->belongsTo(Certificate::class);
    }

    public function handler(): BelongsTo
    {
        return $this->belongsTo(User::class, 'handled_by');
    }
}
