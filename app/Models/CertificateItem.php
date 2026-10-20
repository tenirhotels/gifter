<?php

namespace App\Models;

use App\Exceptions\DomainRuleViolation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Строка состава сертификата со снимком названия и цены на дату продажи.
 * Себестоимость, поставщик и признаки исполнения тоже фиксируются на дату продажи.
 * От текущего каталога и прайса не зависит. Менять можно только в черновике.
 */
class CertificateItem extends Model
{
    protected $fillable = [
        'certificate_id', 'service_id', 'provider_id', 'price_version_id', 'service_name_snapshot', 'unit_snapshot',
        'quantity', 'unit_price', 'line_total', 'unit_cost', 'line_cost', 'position', 'from_package', 'paid_by_customer',
        'requires_fulfillment', 'requires_scheduling', 'requires_confirmation',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_price' => 'decimal:2',
            'line_total' => 'decimal:2',
            'unit_cost' => 'decimal:2',
            'line_cost' => 'decimal:2',
            'from_package' => 'boolean',
            'paid_by_customer' => 'boolean',
            'requires_fulfillment' => 'boolean',
            'requires_scheduling' => 'boolean',
            'requires_confirmation' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        $guard = function (self $item) {
            if (! $item->certificate->isDraft()) {
                throw new DomainRuleViolation(__('Состав выпущенного сертификата изменить нельзя.'));
            }
        };
        static::updating($guard);
        static::deleting($guard);
    }

    public function certificate(): BelongsTo
    {
        return $this->belongsTo(Certificate::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class);
    }

    public function priceVersion(): BelongsTo
    {
        return $this->belongsTo(ServicePrice::class, 'price_version_id');
    }
}
