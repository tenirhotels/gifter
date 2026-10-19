<?php

namespace App\Models;

use App\Models\Concerns\PreventsDeletion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Выплата комиссии. Не удаляется; отмена помечает запись (дата, кто, причина). */
class CommissionPayment extends Model
{
    use PreventsDeletion;

    protected $fillable = ['commission_id', 'amount', 'paid_at', 'paid_by', 'reference', 'comment', 'reversed_at', 'reversed_by', 'reversal_reason'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'paid_at' => 'datetime', 'reversed_at' => 'datetime'];
    }

    public function commission(): BelongsTo
    {
        return $this->belongsTo(CertificateCommission::class, 'commission_id');
    }

    public function payer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_by');
    }
}
