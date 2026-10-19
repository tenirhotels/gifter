<?php

namespace App\Models;

use App\Enums\LegalForm;
use App\Enums\ProviderType;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\PreventsDeletion;
use App\Support\PhoneNumber;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Кто оказывает услугу: отель, ресторан, отдел или сторонний поставщик. */
class Provider extends Model
{
    use Auditable, PreventsDeletion;

    protected $attributes = ['is_active' => true];

    protected $fillable = ['name', 'type', 'legal_form', 'legal_name', 'tax_id', 'contact_person', 'phone', 'email', 'notes', 'is_active'];

    protected function casts(): array
    {
        return ['type' => ProviderType::class, 'legal_form' => LegalForm::class, 'is_active' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::saving(function (self $provider) {
            if ($provider->isDirty('phone')) {
                PhoneNumber::assertValid($provider->phone);
                $provider->phone = PhoneNumber::normalize($provider->phone);
            }
        });
    }

    public function isExternal(): bool
    {
        return $this->type === ProviderType::External;
    }

    /** «ТОО Канатная дорога, БИН 940140000385» — для документов и заявок. */
    public function requisites(): ?string
    {
        if (! $this->isExternal() || ! $this->legal_form) {
            return null;
        }

        return trim("{$this->legal_form->getLabel()} {$this->legal_name}").", {$this->legal_form->taxIdLabel()} {$this->tax_id}";
    }

    public function services(): HasMany
    {
        return $this->hasMany(Service::class);
    }
}
