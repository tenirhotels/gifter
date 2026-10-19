<?php

namespace App\Services;

use App\Exceptions\DomainRuleViolation;
use App\Models\AuditLog;
use App\Models\User;

/** Безопасность учётных записей: сброс двухфакторного входа администратором. */
class UserSecurity
{
    /**
     * Сотрудник потерял телефон: сбросить приложение-аутентификатор и коды восстановления.
     * При следующем входе он настроит новое приложение. Причина обязательна, сброс — в журнал аудита.
     */
    public function resetTwoFactor(User $user, string $reason): void
    {
        if (blank(trim($reason))) {
            throw new DomainRuleViolation('Укажите причину сброса.');
        }
        if ($user->is(auth()->user())) {
            throw new DomainRuleViolation('Свой двухфакторный вход меняйте в профиле.');
        }
        if (! $user->hasTwoFactor()) {
            throw new DomainRuleViolation('У пользователя двухфакторный вход ещё не настроен.');
        }

        $user->forceFill(['app_authentication_secret' => null, 'app_authentication_recovery_codes' => null])->save();
        AuditLog::record('two_factor_reset', $user, null, ['reason' => trim($reason)]);
    }
}
