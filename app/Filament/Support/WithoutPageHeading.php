<?php

namespace App\Filament\Support;

use Illuminate\Contracts\Support\Htmlable;

/**
 * Без крупного заголовка страницы: что открыто, видно из строки навигации
 * («Получатели › Антонов Юра Петрович › Редактирование»). Заголовок вкладки браузера остаётся.
 */
trait WithoutPageHeading
{
    public function getHeading(): string|Htmlable
    {
        return '';
    }
}
