<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\PreventsDeletion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ServiceCategory extends Model
{
    use Auditable, PreventsDeletion;

    protected $attributes = ['is_active' => true];

    protected $fillable = ['name', 'position', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function services(): HasMany
    {
        return $this->hasMany(Service::class, 'category_id');
    }
}
