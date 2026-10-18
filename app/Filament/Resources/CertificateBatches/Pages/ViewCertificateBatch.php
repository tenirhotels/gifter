<?php

namespace App\Filament\Resources\CertificateBatches\Pages;

use App\Enums\BatchStatus;
use App\Enums\Permission;
use App\Filament\Resources\CertificateBatches\CertificateBatchResource;
use App\Filament\Support\RunsDomainActions;
use App\Models\CertificateBatch;
use App\Services\CertificateBatchManager;
use App\Services\CertificateDocument;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Pages\ViewRecord;

class ViewCertificateBatch extends ViewRecord
{
    protected static string $resource = CertificateBatchResource::class;

    public function getTitle(): string
    {
        return $this->getRecord()->label();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('approveBatch')->label('Согласовать блок')->icon('heroicon-o-hand-thumb-up')->color('success')
                ->authorize(Permission::ApproveComplimentary)
                ->visible(fn (CertificateBatch $record) => $record->status === BatchStatus::Draft)
                ->modalDescription(fn (CertificateBatch $record) => "Будет согласовано {$record->quantity} бесплатных сертификатов. Маркетинговый расход по себестоимости: "
                    .Money::format($record->certificates()->sum('total_cost')).'.')
                ->schema([Textarea::make('comment')->label('Комментарий')])
                ->action(fn (CertificateBatch $record, array $data, Action $action) => $this->run($action,
                    fn () => app(CertificateBatchManager::class)->approve($record, $data['comment'] ?? null), 'Блок согласован')),

            Action::make('issueBatch')->label('Выпустить блок')->icon('heroicon-o-check-badge')->color('success')
                ->authorize(Permission::CreateComplimentary)
                ->visible(fn (CertificateBatch $record) => $record->status === BatchStatus::Approved)
                ->requiresConfirmation()
                ->modalDescription(fn (CertificateBatch $record) => "Будут выпущены все {$record->quantity} сертификатов блока.")
                ->action(fn (CertificateBatch $record, Action $action) => $this->run($action,
                    fn () => app(CertificateBatchManager::class)->issue($record), 'Блок выпущен')),

            Action::make('downloadBatchPdf')->label('PDF всего блока')->icon('heroicon-o-arrow-down-tray')->color('gray')
                ->visible(fn (CertificateBatch $record) => $record->status === BatchStatus::Issued)
                ->action(function (CertificateBatch $record) {
                    $pdf = app(CertificateDocument::class)->batchPdf($record);

                    return response()->streamDownload(fn () => print ($pdf), "batch-{$record->id}.pdf", ['Content-Type' => 'application/pdf']);
                }),

            Action::make('cancelBatch')->label('Аннулировать блок')->icon('heroicon-o-no-symbol')->color('danger')
                ->authorize(Permission::CreateComplimentary)
                ->visible(fn (CertificateBatch $record) => in_array($record->status, [BatchStatus::Draft, BatchStatus::Approved], true))
                ->schema([Textarea::make('reason')->label('Причина')->required()])
                ->action(fn (CertificateBatch $record, array $data, Action $action) => $this->run($action,
                    fn () => app(CertificateBatchManager::class)->cancel($record, $data['reason']), 'Блок аннулирован')),
        ];
    }

    private function run(Action $action, callable $callback, string $success): void
    {
        RunsDomainActions::run($action, $callback, $success);
        $this->redirect(CertificateBatchResource::getUrl('view', ['record' => $this->getRecord()]));
    }
}
