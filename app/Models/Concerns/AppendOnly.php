<?php

namespace App\Models\Concerns;

use App\Exceptions\DomainRuleViolation;

/**
 * Журнальная запись: только вставка, без изменения и удаления.
 */
trait AppendOnly
{
    public static function bootAppendOnly(): void
    {
        static::updating(fn () => throw new DomainRuleViolation(__('Журнальную запись нельзя изменить.')));
        static::deleting(fn () => throw new DomainRuleViolation(__('Журнальную запись нельзя удалить.')));
    }
}
