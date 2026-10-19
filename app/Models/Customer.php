<?php

namespace App\Models;

use App\Enums\CustomerType;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasEncryptedContacts;
use App\Models\Concerns\HasPersonName;
use App\Models\Concerns\PreventsDeletion;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Заказчик сертификата (платного или бесплатного): физлицо или компания (тогда обязателен БИН,
 * а ФИО — контактное лицо компании).
 */
class Customer extends Model
{
    use Auditable, HasEncryptedContacts, HasFactory, HasPersonName, PreventsDeletion;

    protected $attributes = ['type' => 'individual'];

    protected $fillable = ['type', 'last_name', 'first_name', 'middle_name', 'phone', 'email', 'company', 'bin', 'notes'];

    protected function casts(): array
    {
        return ['type' => CustomerType::class];
    }

    public function isCompany(): bool
    {
        return $this->type === CustomerType::Company;
    }

    /** Как показывать в списках и выборе: компания — с контактным лицом. */
    public function displayName(): string
    {
        return $this->isCompany() && filled($this->full_name) ? "{$this->company} ({$this->full_name})" : ($this->isCompany() ? (string) $this->company : $this->full_name);
    }

    public function certificates(): HasMany
    {
        return $this->hasMany(Certificate::class);
    }
}
