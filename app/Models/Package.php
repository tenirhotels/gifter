<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\PreventsDeletion;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Шаблон состава сертификата. На выпущенные сертификаты не влияет. */
class Package extends Model
{
    use Auditable, HasFactory, PreventsDeletion;

    protected $attributes = ['is_active' => true];

    protected $fillable = ['name', 'description', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function items(): HasMany
    {
        return $this->hasMany(PackageItem::class)->orderBy('position');
    }
}
