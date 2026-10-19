<?php

namespace App\Models\Concerns;

use App\Models\AuditLog;

/**
 * Пишет создание, изменение и удаление модели в общий журнал аудита.
 */
trait Auditable
{
    public static function bootAuditable(): void
    {
        static::created(fn ($model) => AuditLog::record('created', $model, null, $model->auditAttributes($model->getAttributes())));

        static::updated(function ($model) {
            $changed = array_keys($model->getChanges());
            $changed = array_diff($changed, ['updated_at', 'remember_token', 'app_authentication_secret', 'app_authentication_recovery_codes']);
            if ($changed === []) {
                return;
            }
            $before = array_intersect_key($model->getOriginal(), array_flip($changed));
            $after = array_intersect_key($model->getAttributes(), array_flip($changed));
            AuditLog::record('updated', $model, $model->auditAttributes($before), $model->auditAttributes($after));
        });

        static::deleted(fn ($model) => AuditLog::record('deleted', $model, $model->auditAttributes($model->getOriginal()), null));
    }

    /** @param array<string, mixed> $attributes */
    protected function auditAttributes(array $attributes): array
    {
        $attributes = array_diff_key($attributes, array_flip(['password', 'remember_token', 'app_authentication_secret', 'app_authentication_recovery_codes']));
        if (method_exists($this, 'maskContactsForAudit')) {
            $attributes = $this->maskContactsForAudit($attributes);
        }

        return array_map(fn ($value) => $value instanceof \BackedEnum ? $value->value : $value, $attributes);
    }
}
