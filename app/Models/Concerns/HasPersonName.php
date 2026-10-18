<?php

namespace App\Models\Concerns;

/**
 * Фамилия, имя, отчество — отдельные поля; full_name собирается сам
 * и используется для поиска и отображения.
 */
trait HasPersonName
{
    public static function bootHasPersonName(): void
    {
        static::saving(function ($model) {
            $model->full_name = trim(implode(' ', array_filter([
                trim((string) $model->last_name),
                trim((string) $model->first_name),
                trim((string) $model->middle_name),
            ])));
        });
    }
}
