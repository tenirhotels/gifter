<?php

namespace App\Filament\Resources\CertificateBatches\Pages;

use App\Enums\BatchStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\Permission;
use App\Filament\Resources\CertificateBatches\CertificateBatchResource;
use App\Filament\Support\RunsDomainActions;
use App\Filament\Support\WithoutPageHeading;
use App\Models\CertificateBatch;
use App\Services\CertificateBatchManager;
use App\Services\CertificateDocument;
use App\Services\PaymentManager;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Carbon;

/** Группа сертификатов (платная или бесплатная): счёт, оплата, согласование и выпуск — для всех её сертификатов сразу. */
class ViewCertificateBatch extends ViewRecord
{
    use WithoutPageHeading;

    protected static string $resource = CertificateBatchResource::class;

    public function getTitle(): string
    {
        return $this->getRecord()->label();
    }

    protected function getHeaderActions(): array
    {
        // Счёт группы: платная — в черновике; бесплатная — только если есть платные доп. услуги (до и после согласования).
        $paidDraft = fn (CertificateBatch $record) => $record->isComplimentary()
            ? in_array($record->status, [BatchStatus::Draft, BatchStatus::Approved], true) && $record->certificates()->sum('payable_amount') > 0
            : $record->status === BatchStatus::Draft;
        $pendingLine = fn (CertificateBatch $record) => $record->payments()->where('status', PaymentStatus::Pending)->first();

        return [
            Action::make('approveBatch')->label('Согласовать')->icon('heroicon-o-hand-thumb-up')->color('success')
                ->authorize(Permission::ApproveComplimentary)
                ->visible(fn (CertificateBatch $record) => $record->isComplimentary() && $record->status === BatchStatus::Draft)
                ->modalDescription(fn (CertificateBatch $record) => __('Будет согласовано :p1 бесплатных сертификатов', ['p1' => $record->quantity])
                    .($record->valid_from ? __(', срок :p1 — :p2 (задан вручную)', ['p1' => $record->valid_from->format('d.m.Y'), 'p2' => $record->valid_until->format('d.m.Y')]) : __(', срок по пакету'))
                    .__('. Маркетинговый расход по себестоимости: :p1', ['p1' => Money::format($record->certificates()->sum('marketing_cost'))])
                    .($record->certificates()->sum('payable_amount') > 0 ? __('. Платные услуги оплачивает заказчик: :p1 (выпуск — после оплаты)', ['p1' => Money::format($record->certificates()->sum('payable_amount'))]) : '').'.')
                ->schema([Textarea::make('comment')->label('Комментарий')])
                ->action(fn (CertificateBatch $record, array $data, Action $action) => $this->run($action,
                    fn () => app(CertificateBatchManager::class)->approve($record, $data['comment'] ?? null), __('Группа согласована'))),

            Action::make('invoiceBatch')->label('Выставить счёт')->icon('heroicon-o-document-text')
                ->authorize(Permission::ManagePayments)
                ->visible(fn (CertificateBatch $record) => $paidDraft($record)
                    && ! $record->payments()->where('status', '!=', PaymentStatus::Cancelled)->exists())
                ->modalDescription(fn (CertificateBatch $record) => __('Один счёт на всю группу: :p1', ['p1' => Money::format($record->certificates()->sum('payable_amount'))]).($record->isComplimentary() ? __(' (только платные услуги)') : '').'.')
                ->schema([
                    Select::make('method')->label('Способ')->options(PaymentMethod::manualOptions())->required()->default(PaymentMethod::KaspiInvoice->value),
                    TextInput::make('invoice_number')->label('Номер счёта в банке')->maxLength(255),
                    Textarea::make('comment')->label('Комментарий'),
                ])
                ->action(fn (CertificateBatch $record, array $data, Action $action) => $this->run($action, fn () => app(CertificateBatchManager::class)->invoice(
                    $record, $data['method'] instanceof PaymentMethod ? $data['method'] : PaymentMethod::from($data['method']),
                    $data['invoice_number'] ?? null, $data['comment'] ?? null), __('Счёт выставлен'))),

            Action::make('markBatchPaid')->label('Оплачен')->icon('heroicon-o-check')->color('success')
                ->visible(fn (CertificateBatch $record) => $paidDraft($record) && $pendingLine($record) !== null)
                ->authorize(fn (CertificateBatch $record) => auth()->user()->can($pendingLine($record)?->method->requiresFinanceConfirmation()
                    ? Permission::ReceiveBankTransfers : Permission::ReceivePayments))
                ->schema([DateTimePicker::make('paid_at')->label('Дата поступления')->default(now())->maxDate(now())->required()])
                ->action(fn (CertificateBatch $record, array $data, Action $action) => $this->run($action,
                    fn () => app(CertificateBatchManager::class)->markPaid($record, Carbon::parse($data['paid_at'])), __('Оплата отмечена'))),

            Action::make('issueBatch')->label('Выпустить')->icon('heroicon-o-check-badge')->color('success')
                ->authorize(fn (CertificateBatch $record) => auth()->user()->can($record->isComplimentary() ? Permission::CreateComplimentary : Permission::IssueCertificates))
                // Платную — только когда оплачен каждый сертификат (кнопка не показывается раньше времени).
                ->visible(fn (CertificateBatch $record) => CertificateBatch::query()->whereKey($record->getKey())->readyToIssue()->exists())
                ->requiresConfirmation()
                ->modalDescription(fn (CertificateBatch $record) => __('Будут выпущены все :p1 сертификатов: :p2.', ['p1' => $record->quantity, 'p2' => $record->shortLabel()]))
                ->action(fn (CertificateBatch $record, Action $action) => $this->run($action,
                    fn () => app(CertificateBatchManager::class)->issue($record), __('Сертификаты выпущены'))),

            Action::make('downloadBatchPdf')->label('PDF всех')->icon('heroicon-o-arrow-down-tray')->color('gray')
                ->visible(fn (CertificateBatch $record) => $record->status === BatchStatus::Issued)
                ->action(function (CertificateBatch $record) {
                    $pdf = app(CertificateDocument::class)->batchPdf($record);

                    return response()->streamDownload(fn () => print ($pdf), ($record->isComplimentary() ? 'free' : 'purchase')."-{$record->id}.pdf", ['Content-Type' => 'application/pdf']);
                }),

            ActionGroup::make([
                Action::make('reconcileBatch')->label('Сверено')->icon('heroicon-o-document-check')
                    ->authorize(Permission::ReconcilePayments)
                    ->visible(fn (CertificateBatch $record) => $record->payments()->where('status', PaymentStatus::Paid)->whereNull('reconciled_at')->exists())
                    ->requiresConfirmation()
                    ->action(fn (CertificateBatch $record, Action $action) => $this->run($action, fn () => app(PaymentManager::class)
                        ->reconcile($record->payments()->where('status', PaymentStatus::Paid)->whereNull('reconciled_at')->firstOrFail()), __('Счёт группы сверен'))),
                Action::make('cancelBatchInvoice')->label('Отменить счёт')->icon('heroicon-o-x-mark')
                    ->authorize(Permission::ManagePayments)
                    ->visible(fn (CertificateBatch $record) => $paidDraft($record) && $pendingLine($record) !== null)
                    ->schema([Textarea::make('reason')->label('Причина')->required()])
                    ->action(fn (CertificateBatch $record, array $data, Action $action) => $this->run($action,
                        fn () => app(CertificateBatchManager::class)->cancelInvoice($record, $data['reason']), __('Счёт отменён'))),
                Action::make('cancelBatch')->label(fn (CertificateBatch $record) => __('Аннулировать группу'))->icon('heroicon-o-no-symbol')->color('danger')
                    ->authorize(fn (CertificateBatch $record) => auth()->user()->can($record->isComplimentary() ? Permission::CreateComplimentary : Permission::CancelCertificates))
                    ->visible(fn (CertificateBatch $record) => in_array($record->status, [BatchStatus::Draft, BatchStatus::Approved], true))
                    ->schema([Textarea::make('reason')->label('Причина')->required()])
                    ->action(fn (CertificateBatch $record, array $data, Action $action) => $this->run($action,
                        fn () => app(CertificateBatchManager::class)->cancel($record, $data['reason']), __('Сертификаты аннулированы'))),
            ])->label('Ещё')->button()->color('gray'),
        ];
    }

    private function run(Action $action, callable $callback, string $success): void
    {
        RunsDomainActions::run($action, $callback, $success);
        $this->redirect(CertificateBatchResource::getUrl('view', ['record' => $this->getRecord()]));
    }
}
