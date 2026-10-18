<?php

namespace App\Models;

use App\Enums\ProviderType;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\PreventsDeletion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Кто оказывает услугу: отель, ресторан, отдел или сторонний поставщик. */
class Provider extends Model
{
    use Auditable, PreventsDeletion;

    protected $attributes = ['is_active' => true];

    protected $fillable = ['name', 'type', 'contact_person', 'phone', 'email', 'notes', 'is_active'];

    protected function casts(): array
    {
        return ['type' => ProviderType::class, 'is_active' => 'boolean'];
    }

    public function services(): HasMany
    {
        return $this->hasMany(Service::class);
    }
}
