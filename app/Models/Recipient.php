<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\PreventsDeletion;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Получатель (гость), на которого оформлен сертификат. */
class Recipient extends Model
{
    use Auditable, HasFactory, PreventsDeletion;

    protected $fillable = ['full_name', 'phone', 'email', 'notes'];

    public function certificates(): HasMany
    {
        return $this->hasMany(Certificate::class);
    }
}
