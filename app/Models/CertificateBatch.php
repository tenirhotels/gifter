<?php

namespace App\Models;

use App\Enums\BatchStatus;
use App\Enums\ComplimentaryPurpose;
use App\Models\Concerns\PreventsDeletion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Блок бесплатных сертификатов одного пакета: создаётся, согласуется
 * директором и выпускается целиком. Получатели указываются позже, по одному.
 */
class CertificateBatch extends Model
{
    use PreventsDeletion;

    protected $fillable = [
        'package_id', 'customer_id', 'quantity', 'purpose', 'reason', 'status',
        'created_by', 'approved_by', 'approved_at', 'issued_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => BatchStatus::class,
            'purpose' => ComplimentaryPurpose::class,
            'quantity' => 'integer',
            'approved_at' => 'datetime',
            'issued_at' => 'datetime',
        ];
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(Package::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function certificates(): HasMany
    {
        return $this->hasMany(Certificate::class, 'batch_id')->orderBy('id');
    }

    /** «Блок №3 · Блогеры · 10 шт.» */
    public function label(): string
    {
        return "Блок №{$this->id} · {$this->purpose->getLabel()} · {$this->quantity} шт.";
    }
}
