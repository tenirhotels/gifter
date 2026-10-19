<?php

namespace App\Filament\Pages;

use App\Enums\Permission;
use App\Filament\Support\TranslatesPageLabels;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\SecurityAudit;
use App\Support\Backups;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Number;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use UnitEnum;

/**
 * Безопасность сервера для администратора: итог проверки настроек (gifter:security-check),
 * последняя резервная копия и её скачивание для хранения вне хостинга (с записью в аудит).
 */
class Security extends Page
{
    use TranslatesPageLabels;

    protected string $view = 'filament.pages.security';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLockClosed;

    protected static string|UnitEnum|null $navigationGroup = 'Администрирование';

    protected static ?int $navigationSort = 90;

    protected static ?string $navigationLabel = 'Безопасность';

    protected static ?string $title = 'Безопасность';

    protected static ?string $slug = 'security';

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->can(Permission::ManageUsers);
    }

    public static function getNavigationBadge(): ?string
    {
        $failed = self::problems();

        return $failed ? (string) $failed : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    /** Сколько критичных ошибок нашла последняя проверка. */
    public static function problems(): int
    {
        return collect(app(SecurityAudit::class)->lastResult()['results'] ?? [])->where('status', SecurityAudit::FAIL)->count();
    }

    /** Быстрые проверки — при каждом открытии, чтобы итог не расходился с текущим состоянием. */
    public function mount(): void
    {
        app(SecurityAudit::class)->run(checkHttp: false);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('check')->label('Проверить сейчас')->icon('heroicon-o-arrow-path')
                ->action(function () {
                    app(SecurityAudit::class)->run();
                    Notification::make()->success()->title('Проверка выполнена')->send();
                }),
            Action::make('download')->label('Скачать последнюю копию')->icon('heroicon-o-arrow-down-tray')->color('gray')
                ->visible(fn (): bool => Backups::latest() !== null)
                ->requiresConfirmation()
                ->modalHeading('Скачать резервную копию базы')
                ->modalDescription('Архив зашифрован паролем BACKUP_ARCHIVE_PASSWORD. Храните его вне хостинга (облако, внешний диск). Скачивание записывается в журнал аудита.')
                ->modalSubmitActionLabel('Скачать')
                ->action(function (): ?BinaryFileResponse {
                    $backup = Backups::latest();
                    if (! $backup) {
                        return null;
                    }
                    AuditLog::record('backup_downloaded', auth()->user(), null, ['file' => basename($backup['path'])]);

                    return response()->download(Backups::absolutePath($backup['path']));
                }),
        ];
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $last = app(SecurityAudit::class)->lastResult();
        $backup = Backups::latest();

        return [
            'checkedAt' => isset($last['checked_at']) ? now()->parse($last['checked_at'])->timezone(config('app.timezone'))->format('d.m.Y H:i') : null,
            'results' => $last['results'] ?? [],
            'backup' => $backup ? [
                'date' => $backup['created_at']->format('d.m.Y H:i'),
                'size' => Number::fileSize($backup['size'], 1),
            ] : null,
            'withoutTwoFactor' => User::query()->where('is_active', true)->whereNull('app_authentication_secret')->pluck('name')->all(),
        ];
    }
}
