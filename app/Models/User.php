<?php

namespace App\Models;

use App\Enums\Permission;
use App\Enums\Role;
use App\Exceptions\DomainRuleViolation;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\PreventsDeletion;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Casts\AsEnumCollection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use Auditable, HasFactory, Notifiable, PreventsDeletion;

    /**
     * @var list<string>
     */
    protected $attributes = ['is_active' => true];

    protected $fillable = [
        'name',
        'email',
        'password',
        'is_active',
        'roles',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'roles' => AsEnumCollection::of(Role::class),
        ];
    }

    protected static function booted(): void
    {
        // Нельзя остаться без активного администратора.
        static::updating(function (self $user) {
            $wasAdmin = (bool) $user->getOriginal('is_active') && in_array(Role::SuperAdmin->value, json_decode($user->getRawOriginal('roles') ?? '[]', true) ?? [], true);
            if ($wasAdmin && ! ($user->is_active && $user->isSuperAdmin())) {
                $others = static::query()->whereKeyNot($user->id)->where('is_active', true)->get()->contains(fn (self $u) => $u->isSuperAdmin());
                if (! $others) {
                    throw new DomainRuleViolation('Это последний активный администратор — сначала назначьте другого.');
                }
            }
        });
    }

    /** В панель входит активный пользователь хотя бы с одной ролью. */
    public function canAccessPanel(Panel $panel): bool
    {
        return $this->is_active && $this->roles?->isNotEmpty();
    }

    public function hasRole(Role ...$roles): bool
    {
        return $this->roles?->contains(fn (Role $role) => in_array($role, $roles, true)) ?? false;
    }

    public function isSuperAdmin(): bool
    {
        return $this->hasRole(Role::SuperAdmin);
    }

    public function hasPermission(Permission $permission): bool
    {
        return $this->is_active && ($this->isSuperAdmin() || $this->hasRole(...$permission->roles()));
    }
}
