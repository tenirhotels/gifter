<?php

namespace App\Models;

use App\Enums\FulfillmentStatus;
use App\Models\Concerns\PreventsDeletion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Заявка на исполнение услуги сертификата (ужин, трансфер, билеты): кому, когда,
 * подтверждено ли. Создаётся при отметке брони; меняется только через FulfillmentManager.
 */
class ServiceFulfillment extends Model
{
    use PreventsDeletion;

    protected $fillable = [
        'certificate_id', 'certificate_item_id', 'provider_id', 'status', 'requires_scheduling', 'requires_confirmation',
        'scheduled_date', 'scheduled_time', 'request_details', 'requested_at', 'confirmed_at', 'completed_at',
        'cancelled_at', 'cancellation_reason', 'notes', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => FulfillmentStatus::class,
            'requires_scheduling' => 'boolean',
            'requires_confirmation' => 'boolean',
            'scheduled_date' => 'date',
            'requested_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function certificate(): BelongsTo
    {
        return $this->belongsTo(Certificate::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(CertificateItem::class, 'certificate_item_id');
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class);
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /** «Ужин × 2» — по снимку строки сертификата. */
    public function serviceLabel(): string
    {
        return "{$this->item?->service_name_snapshot} × {$this->item?->quantity}";
    }

    /** «20.10.2026 19:00» или null. */
    public function whenLabel(): ?string
    {
        return $this->scheduled_date ? trim($this->scheduled_date->format('d.m.Y').' '.$this->scheduled_time) : null;
    }
}
