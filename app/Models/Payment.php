<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Concerns\PreventsDeletion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Счёт на оплату сертификата (Kaspi / Halyk), отмечается вручную. */
class Payment extends Model
{
    use PreventsDeletion;

    protected $fillable = [
        'certificate_id', 'batch_id', 'method', 'status', 'amount', 'currency', 'invoice_number', 'invoiced_at',
        'paid_at', 'cancelled_at', 'reconciled_at', 'reconciled_by', 'comment', 'recorded_by',
    ];

    protected function casts(): array
    {
        return [
            'method' => PaymentMethod::class,
            'status' => PaymentStatus::class,
            'amount' => 'decimal:2',
            'invoiced_at' => 'datetime',
            'paid_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'reconciled_at' => 'datetime',
        ];
    }

    public function certificate(): BelongsTo
    {
        return $this->belongsTo(Certificate::class);
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(CertificateBatch::class, 'batch_id');
    }

    public function reconciler(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reconciled_by');
    }

    public function isReconciled(): bool
    {
        return $this->reconciled_at !== null;
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
