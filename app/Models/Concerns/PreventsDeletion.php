<?php

namespace App\Models\Concerns;

use App\Exceptions\DomainRuleViolation;

/**
 * Запись нельзя удалить физически: вместо удаления — статус.
 */
trait PreventsDeletion
{
    public static function bootPreventsDeletion(): void
    {
        static::deleting(function ($model) {
            throw new DomainRuleViolation(class_basename($model).' нельзя удалить.');
        });
    }
}
