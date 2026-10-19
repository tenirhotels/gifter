<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasEncryptedContacts;
use App\Models\Concerns\HasPersonName;
use App\Models\Concerns\PreventsDeletion;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Получатель (гость), на которого оформлен сертификат. Может быть указан позже. */
class Recipient extends Model
{
    use Auditable, HasEncryptedContacts, HasFactory, HasPersonName, PreventsDeletion;

    protected $fillable = ['last_name', 'first_name', 'middle_name', 'phone', 'email', 'notes'];

    public function certificates(): HasMany
    {
        return $this->hasMany(Certificate::class);
    }
}
