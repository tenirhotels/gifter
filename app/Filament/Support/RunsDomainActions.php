<?php

namespace App\Filament\Support;

use App\Exceptions\DomainRuleViolation;
use Filament\Actions\Action;
use Filament\Notifications\Notification;

/**
 * Выполняет действие сервиса и показывает нарушение бизнес-правила
 * уведомлением, не закрывая модальное окно.
 */
final class RunsDomainActions
{
    public static function run(Action $action, callable $callback, ?string $success = null): void
    {
        try {
            $callback();
        } catch (DomainRuleViolation $e) {
            Notification::make()->danger()->title($e->getMessage())->send();
            $action->halt();
        }

        if ($success) {
            Notification::make()->success()->title(__($success))->send();
        }
    }
}
