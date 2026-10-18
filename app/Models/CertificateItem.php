<?php

namespace App\Models;

use App\Exceptions\DomainRuleViolation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Строка состава сертификата со снимком названия и цены на дату продажи.
 * От текущего каталога и прайса не зависит. Менять можно только в черновике.
 */
class CertificateItem extends Model
{
    protected $fillable = [
        'certificate_id', 'service_id', 'price_version_id', 'service_name_snapshot', 'unit_snapshot',
        'quantity', 'unit_price', 'line_total', 'position',
    ];

    protected function casts(): array
    {
        return ['quantity' => 'integer', 'unit_price' => 'decimal:2', 'line_total' => 'decimal:2'];
    }

    protected static function booted(): void
    {
        $guard = function (self $item) {
            if (! $item->certificate->isDraft()) {
                throw new DomainRuleViolation('Состав выпущенного сертификата изменить нельзя.');
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

    public function priceVersion(): BelongsTo
    {
        return $this->belongsTo(ServicePrice::class, 'price_version_id');
    }
}
