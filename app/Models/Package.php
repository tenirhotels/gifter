<?php

namespace App\Models;

use App\Enums\ComplimentaryPurpose;
use App\Enums\ValidityUnit;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\PreventsDeletion;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Продукт: срок действия и базовый состав сертификата. Задаёт директор по продажам.
 * Продавец выбирает пакет и может добавить доп. услуги; срок не меняет.
 * Бесплатный пакет (инфлюенсеры, партнёры, бартер, лояльность и др.) даёт бесплатные сертификаты:
 * без оплаты, после согласования директором, в т. ч. блоками.
 * Изменение пакета на уже созданные сертификаты не влияет.
 */
class Package extends Model
{
    use Auditable, HasFactory, PreventsDeletion;

    protected $attributes = ['is_active' => true, 'validity_value' => 2, 'validity_unit' => 'months', 'is_complimentary' => false];

    protected $fillable = ['name', 'description', 'validity_value', 'validity_unit', 'is_complimentary', 'complimentary_purpose', 'is_active', 'sold_online', 'image_path'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sold_online' => 'boolean',
            'validity_value' => 'integer',
            'validity_unit' => ValidityUnit::class,
            'is_complimentary' => 'boolean',
            'complimentary_purpose' => ComplimentaryPurpose::class,
        ];
    }

    /** Срок действия сертификата этого пакета, считая с даты продажи. */
    public function validUntil(Carbon $soldAt): Carbon
    {
        return $this->validity_unit->validUntil($soldAt, $this->validity_value);
    }

    public function validityLabel(): string
    {
        return $this->validity_unit->format($this->validity_value).' с даты продажи';
    }

    /** Адрес фото на публичной странице (или null, если фото не загружено). */
    public function imageUrl(): ?string
    {
        return $this->image_path ? url('/media/'.basename($this->image_path)) : null;
    }

    public function items(): HasMany
    {
        return $this->hasMany(PackageItem::class)->orderBy('position');
    }
}
