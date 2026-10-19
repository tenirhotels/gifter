<?php

namespace App\Models;

use App\Enums\ExceptionStatus;
use App\Models\Concerns\PreventsDeletion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Заявка на бронь по исключению для истёкшего сертификата. */
class ReservationException extends Model
{
    use PreventsDeletion;

    protected $fillable = ['certificate_id', 'status', 'booking_number', 'guest_name', 'arrival_date', 'departure_date', 'reason',
        'requested_by', 'decided_by', 'decided_at', 'decision_comment'];

    protected function casts(): array
    {
        return ['status' => ExceptionStatus::class, 'arrival_date' => 'date', 'departure_date' => 'date', 'decided_at' => 'datetime'];
    }

    public function certificate(): BelongsTo
    {
        return $this->belongsTo(Certificate::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
