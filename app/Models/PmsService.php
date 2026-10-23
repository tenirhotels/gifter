<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\PreventsDeletion;
use App\Support\LocalizedName;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Услуга из справочника PMS. Ключ — `external_id` (ID в PMS); название в PMS может быть на разных языках.
 * `allowed_in_certificate` — тумблер «можно включать в сертификат»; `service_id` — услуга Gifter (цена и состав — в Gifter).
 */
class PmsService extends Model
{
    use Auditable, PreventsDeletion;

    protected $fillable = ['external_id', 'name', 'names', 'kind', 'status', 'allowed_in_certificate', 'service_id', 'synced_at', 'missing_since'];

    protected function casts(): array
    {
        return ['names' => 'array', 'allowed_in_certificate' => 'boolean', 'synced_at' => 'datetime', 'missing_since' => 'datetime'];
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function localizedName(?string $lang = null): string
    {
        return LocalizedName::pick($this->names, $this->name, $lang);
    }

    public function isMissing(): bool
    {
        return $this->missing_since !== null;
    }
}
