<?php

namespace App\Filament\Resources\Certificates\Pages;

use App\Enums\CertificateStatus;
use App\Filament\Resources\Certificates\CertificateResource;
use App\Filament\Support\RunsDomainActions;
use App\Models\Certificate;
use App\Services\CertificateManager;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Carbon;

class ViewCertificate extends ViewRecord
{
    protected static string $resource = CertificateResource::class;

    public function getTitle(): string
    {
        return 'Сертификат '.$this->getRecord()->number;
    }

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make()->label('Реквизиты')->visible(fn (Certificate $record) => $record->isDraft()),

            Action::make('issue')->label('Выпустить')->icon('heroicon-o-check-badge')->color('success')
                ->visible(fn (Certificate $record) => $record->isDraft())
                ->requiresConfirmation()
                ->modalDescription('После выпуска состав и цены фиксируются окончательно.')
                ->action(fn (Certificate $record, Action $action) => $this->run($action, fn () => $this->manager()->issue($record), 'Сертификат выпущен')),

            $this->transitionAction('reserve', 'Отметить бронь', CertificateStatus::Active, CertificateStatus::Reserved, 'heroicon-o-calendar'),
            $this->transitionAction('checkIn', 'Заезд', CertificateStatus::Reserved, CertificateStatus::InUse, 'heroicon-o-arrow-right-end-on-rectangle'),
            $this->transitionAction('redeem', 'Погасить', CertificateStatus::InUse, CertificateStatus::Used, 'heroicon-o-check-circle',
                'Сертификат погашается целиком, со всеми услугами. Отменить погашение нельзя.'),

            ActionGroup::make([
                $this->transitionAction('release', 'Снять бронь', CertificateStatus::Reserved, CertificateStatus::Active, 'heroicon-o-arrow-uturn-left'),

                Action::make('changeValidity')->label('Изменить срок')->icon('heroicon-o-clock')
                    ->visible(fn (Certificate $record) => ! $record->isDraft() && in_array($record->status, [CertificateStatus::Active, CertificateStatus::Reserved], true))
                    ->fillForm(fn (Certificate $record) => ['valid_from' => $record->valid_from, 'valid_until' => $record->valid_until])
                    ->schema([
                        DatePicker::make('valid_from')->label('Действует с')->required(),
                        DatePicker::make('valid_until')->label('Действует по')->required()->afterOrEqual('valid_from'),
                        Textarea::make('reason')->label('Причина')->required(),
                    ])
                    ->action(fn (Certificate $record, array $data, Action $action) => $this->run($action, fn () => $this->manager()
                        ->changeValidity($record, Carbon::parse($data['valid_from']), Carbon::parse($data['valid_until']), $data['reason']), 'Срок изменён')),

                Action::make('cancel')->label('Аннулировать')->icon('heroicon-o-no-symbol')->color('danger')
                    ->visible(fn (Certificate $record) => $record->status->canTransitionTo(CertificateStatus::Cancelled))
                    ->schema([Textarea::make('reason')->label('Причина')->required()])
                    ->modalDescription('Сертификат не удаляется: он остаётся в реестре со статусом «Аннулирован».')
                    ->action(fn (Certificate $record, array $data, Action $action) => $this->run($action, fn () => $this->manager()->cancel($record, $data['reason']), 'Сертификат аннулирован')),
            ])->label('Ещё')->button()->color('gray'),
        ];
    }

    private function transitionAction(string $name, string $label, CertificateStatus $from, CertificateStatus $to, string $icon, ?string $warning = null): Action
    {
        return Action::make($name)->label($label)->icon($icon)
            ->visible(fn (Certificate $record) => $record->status === $from)
            ->requiresConfirmation()
            ->modalDescription($warning)
            ->schema([Textarea::make('reason')->label('Комментарий')])
            ->action(fn (Certificate $record, array $data, Action $action) => $this->run($action,
                fn () => $this->manager()->transition($record, $to, $data['reason'] ?? null), "Статус: {$to->getLabel()}"));
    }

    private function run(Action $action, callable $callback, string $success): void
    {
        RunsDomainActions::run($action, $callback, $success);
        $this->redirect(CertificateResource::getUrl('view', ['record' => $this->getRecord()]));
    }

    private function manager(): CertificateManager
    {
        return app(CertificateManager::class);
    }
}
