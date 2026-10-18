<?php

namespace App\Filament\Resources\Certificates\Pages;

use App\Enums\CertificateStatus;
use App\Enums\PaymentMethod;
use App\Enums\Permission;
use App\Filament\Resources\CertificateBatches\CertificateBatchResource;
use App\Filament\Resources\Certificates\CertificateResource;
use App\Filament\Resources\Recipients\Schemas\RecipientForm;
use App\Filament\Support\ContactFields;
use App\Filament\Support\RunsDomainActions;
use App\Models\Certificate;
use App\Models\Recipient;
use App\Services\AdminCorrections;
use App\Services\CertificateDocument;
use App\Services\CertificateManager;
use App\Services\PaymentManager;
use App\Services\RefundManager;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
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

            // У черновика из группы счёт, оплата, согласование, состав и выпуск — на её странице.
            Action::make('openBatch')->icon('heroicon-o-rectangle-stack')
                ->label(fn (Certificate $record) => $record->batch->shortLabel().($record->isDraft() ? ($record->batch->isComplimentary() ? ': согласование, выпуск' : ': счёт, оплата, выпуск') : ''))
                ->color(fn (Certificate $record) => $record->isDraft() ? 'primary' : 'gray')
                ->visible(fn (Certificate $record) => $record->batch_id && auth()->user()?->can('view', $record->batch))
                ->url(fn (Certificate $record) => CertificateBatchResource::getUrl('view', ['record' => $record->batch_id])),

            Action::make('approveComplimentary')->label('Согласовать бесплатный')->icon('heroicon-o-hand-thumb-up')->color('success')
                ->authorize(Permission::ApproveComplimentary)
                ->visible(fn (Certificate $record) => $record->isDraft() && $record->isComplimentary() && ! $record->isComplimentaryApproved() && ! $record->batch_id)
                ->modalDescription(fn (Certificate $record) => 'Срок: '.$record->valid_from->format('d.m.Y').' — '.$record->valid_until->format('d.m.Y')
                    .($record->custom_validity ? ' (задан вручную)' : '').'. Маркетинговый расход по себестоимости: '.Money::format($record->total_cost)
                    .($record->hasUnknownCost() ? ' (у части услуг себестоимость не указана)' : '').'. После согласования состав фиксируется.')
                ->schema([Textarea::make('comment')->label('Комментарий')])
                ->action(fn (Certificate $record, array $data, Action $action) => $this->run($action,
                    fn () => $this->manager()->approveComplimentary($record, $data['comment'] ?? null), 'Бесплатный сертификат согласован')),

            // Счёт и оплата одиночной продажи — сразу в шапке (частичные счета, сверка — во вкладке «Оплата»).
            Action::make('invoice')->label('Выставить счёт')->icon('heroicon-o-document-text')
                ->authorize(Permission::ManagePayments)
                ->visible(fn (Certificate $record) => $this->isOwnSaleDraft($record) && ! $record->pendingPayment()
                    && Money::toMinor($record->invoicedAmount()) < Money::toMinor($record->total_amount))
                ->fillForm(fn (Certificate $record) => [
                    'method' => PaymentMethod::KaspiInvoice->value,
                    'amount' => Money::fromMinor(Money::toMinor($record->total_amount) - Money::toMinor($record->invoicedAmount())),
                ])
                ->schema([
                    Select::make('method')->label('Способ')->options(PaymentMethod::class)->required(),
                    TextInput::make('amount')->label('Сумма, ₸')->numeric()->minValue(1)->required()->helperText('По умолчанию — весь неоплаченный остаток.'),
                    TextInput::make('invoice_number')->label('№ счёта в банке')->maxLength(255),
                ])
                ->action(fn (Certificate $record, array $data, Action $action) => $this->run($action, fn () => app(PaymentManager::class)->invoice(
                    $record, $data['method'] instanceof PaymentMethod ? $data['method'] : PaymentMethod::from($data['method']),
                    (string) $data['amount'], $data['invoice_number'] ?? null), 'Счёт выставлен')),

            Action::make('markPaid')->label('Оплачен')->icon('heroicon-o-check')->color('success')
                ->visible(fn (Certificate $record) => $this->isOwnSaleDraft($record) && $record->pendingPayment() !== null)
                ->authorize(fn (Certificate $record) => auth()->user()->can($record->pendingPayment()?->method->requiresFinanceConfirmation()
                    ? Permission::ReceiveBankTransfers : Permission::ReceivePayments))
                ->modalDescription(fn (Certificate $record) => ($line = $record->pendingPayment())
                    ? $line->method->getLabel().($line->invoice_number ? ' № '.$line->invoice_number : '').' · '.Money::format($line->amount) : null)
                ->schema([DateTimePicker::make('paid_at')->label('Дата поступления')->default(now())->maxDate(now())->required()])
                ->action(fn (Certificate $record, array $data, Action $action) => $this->run($action,
                    fn () => app(PaymentManager::class)->markPaid($record->pendingPayment(), Carbon::parse($data['paid_at'])), 'Оплата отмечена')),

            Action::make('issue')->label('Выпустить')->icon('heroicon-o-check-badge')->color('success')
                ->authorize(Permission::IssueCertificates)
                // Кнопка появляется, когда выпуск возможен: продажа оплачена, бесплатный согласован.
                ->visible(fn (Certificate $record) => ! $record->batch_id && $record->isReadyToIssue())
                ->requiresConfirmation()
                ->modalDescription('После выпуска состав и цены фиксируются окончательно.')
                ->action(fn (Certificate $record, Action $action) => $this->run($action, fn () => $this->manager()->issue($record), 'Сертификат выпущен')),

            Action::make('downloadPdf')->label('PDF')->icon('heroicon-o-arrow-down-tray')->color('gray')
                ->visible(fn (Certificate $record) => $record->isPrintable())
                ->action(function (Certificate $record) {
                    $documents = app(CertificateDocument::class);
                    $pdf = $documents->download($record);

                    return response()->streamDownload(fn () => print ($pdf), $documents->filename($record), ['Content-Type' => 'application/pdf']);
                }),

            $this->transitionAction('reserve', 'Отметить бронь', CertificateStatus::Active, CertificateStatus::Reserved, 'heroicon-o-calendar', Permission::ManageReservations),
            $this->transitionAction('checkIn', 'Заезд', CertificateStatus::Reserved, CertificateStatus::InUse, 'heroicon-o-arrow-right-end-on-rectangle', Permission::ManageReservations),
            $this->transitionAction('redeem', 'Погасить', CertificateStatus::InUse, CertificateStatus::Used, 'heroicon-o-check-circle', Permission::RedeemCertificates,
                'Сертификат погашается целиком, со всеми услугами. Отменить погашение нельзя.'),

            ActionGroup::make([
                Action::make('setRecipient')->label(fn (Certificate $record) => $record->recipient_id ? 'Сменить получателя' : 'Указать получателя')
                    ->icon('heroicon-o-user-plus')
                    ->authorize(Permission::AssignRecipient)
                    ->visible(fn (Certificate $record) => ! $record->isDraft() && ! $record->status->isFinal() && $record->status !== CertificateStatus::InUse)
                    ->fillForm(fn (Certificate $record) => ['recipient_id' => $record->recipient_id])
                    ->schema([
                        Select::make('recipient_id')->label('Получатель')->required()
                            ->options(fn () => Recipient::query()->orderBy('last_name')->pluck('full_name', 'id'))->searchable()
                            ->createOptionForm(RecipientForm::fields())
                            ->createOptionUsing(fn (array $data) => Recipient::create($data)->getKey()),
                    ])
                    ->action(fn (Certificate $record, array $data, Action $action) => $this->run($action,
                        fn () => $this->manager()->setRecipient($record, (int) $data['recipient_id']), 'Получатель указан')),
                ContactFields::revealAction('revealCustomer', fn (Certificate $record) => $record->customer, 'Контакты заказчика'),
                ContactFields::revealAction('revealRecipient', fn (Certificate $record) => $record->recipient, 'Контакты получателя'),

                $this->transitionAction('release', 'Снять бронь', CertificateStatus::Reserved, CertificateStatus::Active, 'heroicon-o-arrow-uturn-left', Permission::ManageReservations),

                Action::make('changeValidity')->label('Изменить срок')->icon('heroicon-o-clock')
                    ->authorize(Permission::ChangeValidity)
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
                    ->authorize(Permission::CancelCertificates)
                    ->visible(fn (Certificate $record) => $record->status->canTransitionTo(CertificateStatus::Cancelled)
                        && Money::toMinor($record->paidAmount()) === 0 && ! $record->hasPendingRefund()
                        && ! ($record->isDraft() && $record->batch_id))
                    ->schema([Textarea::make('reason')->label('Причина')->required()])
                    ->modalDescription('Сертификат не удаляется: он остаётся в реестре со статусом «Аннулирован».')
                    ->action(fn (Certificate $record, array $data, Action $action) => $this->run($action, fn () => $this->manager()->cancel($record, $data['reason']), 'Сертификат аннулирован')),

                Action::make('requestRefund')->label('Возврат и аннулирование')->icon('heroicon-o-receipt-refund')->color('danger')
                    ->authorize(Permission::RequestRefunds)
                    ->visible(fn (Certificate $record) => $record->status->canTransitionTo(CertificateStatus::Cancelled)
                        && Money::toMinor($record->paidAmount()) > 0 && ! $record->hasPendingRefund())
                    ->modalDescription('Редкий случай. Запрос уйдёт на согласование Финансам; до решения сертификат заблокирован. После согласования он будет аннулирован.')
                    ->fillForm(fn (Certificate $record) => ['amount' => $record->paidAmount()])
                    ->schema([
                        TextInput::make('amount')->label('Сумма к возврату, ₸')->numeric()->minValue(0)->required()
                            ->helperText('От 0 до суммы поступивших оплат.'),
                        Textarea::make('reason')->label('Причина и с кем согласовано')->required(),
                    ])
                    ->action(fn (Certificate $record, array $data, Action $action) => $this->run($action, fn () => app(RefundManager::class)
                        ->request($record, (string) $data['amount'], $data['reason']), 'Запрос на возврат отправлен Финансам')),
            ])->label('Ещё')->button()->color('gray'),

            ActionGroup::make([
                Action::make('adminSetStatus')->label('Исправить статус')->icon('heroicon-o-wrench-screwdriver')
                    ->authorize(Permission::AdminCorrections)
                    ->modalDescription('Для исправления ошибок: обычные правила переходов не действуют. Прежняя история сохраняется, исправление записывается отдельно.')
                    ->schema([
                        Select::make('status')->label('Новый статус')->options(CertificateStatus::class)->required(),
                        Textarea::make('reason')->label('Причина')->required(),
                    ])
                    ->action(fn (Certificate $record, array $data, Action $action) => $this->run($action, fn () => app(AdminCorrections::class)
                        ->setStatus($record, $data['status'] instanceof CertificateStatus ? $data['status'] : CertificateStatus::from($data['status']), $data['reason']), 'Статус исправлен')),
                Action::make('adminRevokeApproval')->label('Отозвать согласование бесплатного')->icon('heroicon-o-hand-thumb-down')
                    ->authorize(Permission::AdminCorrections)
                    ->visible(fn (Certificate $record) => $record->isDraft() && $record->isComplimentaryApproved())
                    ->schema([Textarea::make('reason')->label('Причина')->required()])
                    ->action(fn (Certificate $record, array $data, Action $action) => $this->run($action, fn () => app(AdminCorrections::class)
                        ->revokeComplimentaryApproval($record, $data['reason']), 'Согласование отозвано')),
            ])->label('Исправление')->icon('heroicon-o-wrench-screwdriver')->button()->color('danger')
                ->visible(fn () => auth()->user()?->can(Permission::AdminCorrections)),
        ];
    }

    private function transitionAction(string $name, string $label, CertificateStatus $from, CertificateStatus $to, string $icon, Permission $permission, ?string $warning = null): Action
    {
        return Action::make($name)->label($label)->icon($icon)
            ->authorize($permission)
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

    private function isOwnSaleDraft(Certificate $record): bool
    {
        return $record->isDraft() && ! $record->isComplimentary() && ! $record->batch_id;
    }

    private function manager(): CertificateManager
    {
        return app(CertificateManager::class);
    }
}
