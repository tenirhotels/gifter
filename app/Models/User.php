<?php

namespace App\Models;

use App\Enums\Permission;
use App\Enums\Role;
use App\Exceptions\DomainRuleViolation;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\PreventsDeletion;
use Database\Factories\UserFactory;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthentication;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthenticationRecovery;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Casts\AsEnumCollection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable implements FilamentUser, HasAppAuthentication, HasAppAuthenticationRecovery
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
        'locale',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'app_authentication_secret',
        'app_authentication_recovery_codes',
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
            'app_authentication_secret' => 'encrypted',
            'app_authentication_recovery_codes' => 'encrypted:array',
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
                    throw new DomainRuleViolation(__('Это последний активный администратор — сначала назначьте другого.'));
                }
            }
        });
    }

    /** В панель входит активный пользователь хотя бы с одной ролью. */
    public function canAccessPanel(Panel $panel): bool
    {
        return $this->is_active && $this->roles?->isNotEmpty();
    }

    /** Сотрудники, которые могут быть продавцом сертификата (для выбора). @return array<int, string> */
    public static function sellerOptions(): array
    {
        return static::query()->where('is_active', true)->orderBy('name')->get()
            ->filter(fn (self $user) => $user->hasPermission(Permission::CreateCertificates))
            ->mapWithKeys(fn (self $user) => [$user->id => $user->name])->all();
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

    // Двухфакторный вход (обязателен всем): приложение-аутентификатор и коды восстановления.

    public function getAppAuthenticationSecret(): ?string
    {
        return $this->app_authentication_secret;
    }

    public function saveAppAuthenticationSecret(#[\SensitiveParameter] ?string $secret): void
    {
        $wasEnabled = $this->hasTwoFactor();
        $this->app_authentication_secret = $secret;
        $this->save();
        if ($wasEnabled !== $this->hasTwoFactor()) {
            AuditLog::record($this->hasTwoFactor() ? 'two_factor_enabled' : 'two_factor_disabled', $this, null, null);
        }
    }

    public function getAppAuthenticationHolderName(): string
    {
        return $this->email;
    }

    public function getAppAuthenticationRecoveryCodes(): ?array
    {
        return $this->app_authentication_recovery_codes;
    }

    public function saveAppAuthenticationRecoveryCodes(#[\SensitiveParameter] ?array $codes): void
    {
        $this->app_authentication_recovery_codes = $codes;
        $this->save();
    }

    public function hasTwoFactor(): bool
    {
        return filled($this->app_authentication_secret);
    }
}
