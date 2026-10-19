<?php

namespace App\Filament\Support;

/** Названия раздела и записи — на языке интерфейса (lang/kk.json, lang/en.json; ключ — русский текст). */
trait TranslatesResourceLabels
{
    public static function getNavigationLabel(): string
    {
        return __(parent::getNavigationLabel());
    }

    public static function getModelLabel(): string
    {
        return __(parent::getModelLabel());
    }

    public static function getPluralModelLabel(): string
    {
        return __(parent::getPluralModelLabel());
    }
}
