<?php

namespace App\Models;

use App\Enums\ComplimentaryPurpose;
use App\Enums\PackageValidityType;
use App\Enums\ValidityUnit;
use App\Exceptions\DomainRuleViolation;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\PreventsDeletion;
use App\Support\LocalizedName;
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

    protected $fillable = ['name', 'name_translations', 'description', 'description_translations', 'validity_type', 'period_from', 'period_until', 'sales_limits_enabled', 'sales_quota', 'sales_from', 'sales_until', 'validity_value', 'validity_unit', 'is_complimentary', 'complimentary_purpose', 'is_active', 'sold_online', 'shop_position', 'image_path'];

    protected function casts(): array
    {
        return [
            'name_translations' => 'array',
            'description_translations' => 'array',
            'is_active' => 'boolean',
            'sold_online' => 'boolean',
            'shop_position' => 'integer',
            'validity_type' => PackageValidityType::class,
            'period_from' => 'date',
            'period_until' => 'date',
            'sales_limits_enabled' => 'boolean',
            'sales_quota' => 'integer',
            'sales_from' => 'date',
            'sales_until' => 'date',
            'validity_value' => 'integer',
            'validity_unit' => ValidityUnit::class,
            'is_complimentary' => 'boolean',
            'complimentary_purpose' => ComplimentaryPurpose::class,
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $package) {
            $package->name_translations = LocalizedName::clean($package->name_translations);
            $package->description_translations = LocalizedName::clean($package->description_translations);
            if (! $package->sales_limits_enabled) {
                $package->sales_quota = $package->sales_from = $package->sales_until = null;
            } else {
                $package->sales_quota = (int) $package->sales_quota > 0 ? (int) $package->sales_quota : null;
                if ($package->sales_from && $package->sales_until && $package->sales_until->lt($package->sales_from)) {
                    throw new DomainRuleViolation(__('Окно продаж: дата «по» не раньше даты «с».'));
                }
            }
            if ($package->validity_type === PackageValidityType::Period) {
                if ($package->period_from === null || $package->period_until === null || $package->period_until->lt($package->period_from)) {
                    throw new DomainRuleViolation(__('Укажите период пакета: дата «по» не раньше даты «с».'));
                }
            } else {
                $package->period_from = $package->period_until = null;
            }
        });
    }

    public function localizedName(?string $lang = null): string
    {
        return LocalizedName::pick($this->name_translations, (string) $this->name, $lang);
    }

    public function localizedDescription(?string $lang = null): string
    {
        return LocalizedName::pick($this->description_translations, (string) $this->description, $lang);
    }

    public function isFixedPeriod(): bool
    {
        return $this->validity_type === PackageValidityType::Period && $this->period_from !== null && $this->period_until !== null;
    }

    /** Период закончился: такой пакет уже не продаётся. */
    public function periodEnded(?Carbon $on = null): bool
    {
        return $this->isFixedPeriod() && $this->period_until->lt(($on ?? today())->copy()->startOfDay());
    }

    /** Срок действия сертификата этого пакета: конкретный период или срок, считая с даты продажи. @return array{0: Carbon, 1: Carbon} */
    public function validityRange(Carbon $soldAt): array
    {
        if ($this->isFixedPeriod()) {
            return [$this->period_from->copy(), $this->period_until->copy()];
        }

        return [$soldAt->copy(), $this->validity_unit->validUntil($soldAt, $this->validity_value)];
    }

    public function validUntil(Carbon $soldAt): Carbon
    {
        return $this->validityRange($soldAt)[1];
    }

    public function validityLabel(): string
    {
        return $this->validityText('ru');
    }

    /** «2 месяца с даты продажи» или «с 01.12.2026 по 01.02.2027» — на языке посетителя. */
    public function validityText(?string $lang = null): string
    {
        $lang ??= app()->getLocale();
        if ($this->isFixedPeriod()) {
            return __('с :p1 по :p2', ['p1' => $this->period_from->format('d.m.Y'), 'p2' => $this->period_until->format('d.m.Y')], $lang);
        }

        return ($lang === 'ru' ? $this->validity_unit->format($this->validity_value) : $this->validity_value.' '.$this->validity_unit->getLabel())
            .' '.__('с даты продажи', [], $lang);
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
