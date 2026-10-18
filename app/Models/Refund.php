<?php

namespace App\Models;

use App\Enums\RefundStatus;
use App\Models\Concerns\PreventsDeletion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Возврат денег с аннулированием сертификата. Запрашивает менеджер продаж,
 * согласуют и подтверждают выплату Финансы.
 */
class Refund extends Model
{
    use PreventsDeletion;

    protected $fillable = [
        'certificate_id', 'status', 'amount', 'currency', 'reason', 'requested_by', 'reviewed_by',
        'reviewed_at', 'decision_comment', 'paid_by', 'paid_at', 'payout_reference',
    ];

    protected function casts(): array
    {
        return [
            'status' => RefundStatus::class,
            'amount' => 'decimal:2',
            'reviewed_at' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }

    public function certificate(): BelongsTo
    {
        return $this->belongsTo(Certificate::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function payer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_by');
    }
}
