<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\PreventsDeletion;
use App\Support\LocalizedName;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Услуга каталога. Признаки исполнения определяют, нужна ли по ней
 * операционная подготовка (фаза 5): заявка поставщику, время, подтверждение.
 */
class Service extends Model
{
    use Auditable, HasFactory, PreventsDeletion;

    protected $attributes = [
        'is_active' => true,
        'requires_fulfillment' => false,
        'requires_scheduling' => false,
        'requires_confirmation' => false,
    ];

    protected $fillable = [
        'name', 'name_translations', 'description', 'description_translations', 'category_id', 'provider_id', 'unit', 'is_active',
        'requires_fulfillment', 'requires_scheduling', 'requires_confirmation',
    ];

    protected function casts(): array
    {
        return [
            'name_translations' => 'array',
            'description_translations' => 'array',
            'is_active' => 'boolean',
            'requires_fulfillment' => 'boolean',
            'requires_scheduling' => 'boolean',
            'requires_confirmation' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        // Время и подтверждение имеют смысл только для услуги, которую нужно готовить.
        static::saving(function (self $service) {
            $service->name_translations = LocalizedName::clean($service->name_translations);
            $service->description_translations = LocalizedName::clean($service->description_translations);
            if ($service->requires_scheduling || $service->requires_confirmation) {
                $service->requires_fulfillment = true;
            }
        });
    }

    public function localizedName(?string $lang = null): string
    {
        return LocalizedName::pick($this->name_translations, $this->name, $lang);
    }

    public function localizedDescription(?string $lang = null): string
    {
        return LocalizedName::pick($this->description_translations, (string) $this->description, $lang);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ServiceCategory::class, 'category_id');
    }

    public function pmsService(): HasOne
    {
        return $this->hasOne(PmsService::class);
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class);
    }

    public function prices(): HasMany
    {
        return $this->hasMany(ServicePrice::class)->orderByDesc('valid_from');
    }
}
