<?php

namespace App\Filament\Support;

use Illuminate\Contracts\Support\Htmlable;

/** Название пункта меню и заголовок страницы — на языке интерфейса (lang/kk.json, lang/en.json). */
trait TranslatesPageLabels
{
    public static function getNavigationLabel(): string
    {
        return __(parent::getNavigationLabel());
    }

    public function getTitle(): string|Htmlable
    {
        $title = parent::getTitle();

        return is_string($title) ? __($title) : $title;
    }
}
