<?php

namespace App\Filament\Resources\Certificates\RelationManagers;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\Permission;
use App\Filament\Resources\Certificates\CertificateResource;
use App\Filament\Support\RunsDomainActions;
use App\Models\Certificate;
use App\Models\Payment;
use App\Services\PaymentManager;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Carbon;

/** Счета Kaspi / Halyk. Интеграции нет: оплата отмечается вручную. */
class PaymentsRelationManager extends RelationManager
{
    protected static string $relationship = 'payments';

    protected static ?string $title = 'Оплата';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('method')->label('Способ'),
                TextColumn::make('amount')->label('Сумма')->formatStateUsing(fn ($state) => Money::format($state)),
                TextColumn::make('status')->label('Статус')->badge(),
                TextColumn::make('invoice_number')->label('№ счёта')->placeholder('—'),
                TextColumn::make('invoiced_at')->label('Выставлен')->dateTime('d.m.Y H:i'),
                TextColumn::make('paid_at')->label('Оплачен')->dateTime('d.m.Y H:i')->placeholder('—'),
                TextColumn::make('recorder.name')->label('Кто')->placeholder('—'),
                TextColumn::make('comment')->label('Комментарий')->placeholder('—')->wrap()->toggleable(),
            ])
            ->paginated(false)
            ->headerActions([
                Action::make('invoice')->label('Выставить счёт')->icon('heroicon-o-document-text')
                    ->authorize(Permission::ManagePayments)
                    ->visible(fn () => $this->certificate()->isDraft())
                    ->schema(fn () => [
                        Select::make('method')->label('Способ')->options(PaymentMethod::class)->required()->default(PaymentMethod::KaspiInvoice->value),
                        TextInput::make('amount')->label('Сумма, ₸')->numeric()->minValue(1)->required()
                            ->default(fn () => Money::fromMinor(Money::toMinor($this->certificate()->total_amount) - Money::toMinor($this->certificate()->invoicedAmount())))
                            ->helperText('По умолчанию — весь неоплаченный остаток.'),
                        TextInput::make('invoice_number')->label('№ счёта в банке')->maxLength(255),
                        Textarea::make('comment')->label('Комментарий'),
                    ])
                    ->action(function (array $data, Action $action) {
                        RunsDomainActions::run($action, fn () => app(PaymentManager::class)->invoice(
                            $this->certificate(), PaymentMethod::from($data['method'] instanceof PaymentMethod ? $data['method']->value : $data['method']),
                            (string) $data['amount'], $data['invoice_number'] ?? null, $data['comment'] ?? null,
                        ), 'Счёт выставлен');
                        $this->refreshPage();
                    }),
            ])
            ->recordActions([
                Action::make('markPaid')->label('Оплачен')->icon('heroicon-o-check')->color('success')
                    ->authorize(Permission::ManagePayments)
                    ->visible(fn (Payment $record) => $record->status === PaymentStatus::Pending)
                    ->schema([DateTimePicker::make('paid_at')->label('Дата поступления')->default(now())->required()->maxDate(now())])
                    ->action(function (Payment $record, array $data, Action $action) {
                        RunsDomainActions::run($action, fn () => app(PaymentManager::class)->markPaid($record, Carbon::parse($data['paid_at'])), 'Оплата отмечена');
                        $this->refreshPage();
                    }),
                Action::make('cancelPayment')->label('Отменить')->icon('heroicon-o-x-mark')->color('danger')
                    ->authorize(Permission::ManagePayments)
                    ->visible(fn (Payment $record) => $record->status === PaymentStatus::Pending)
                    ->schema([Textarea::make('reason')->label('Причина')->required()])
                    ->action(function (Payment $record, array $data, Action $action) {
                        RunsDomainActions::run($action, fn () => app(PaymentManager::class)->cancel($record, $data['reason']), 'Счёт отменён');
                        $this->refreshPage();
                    }),
            ]);
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    private function certificate(): Certificate
    {
        return $this->getOwnerRecord()->refresh();
    }

    private function refreshPage(): void
    {
        $this->redirect(CertificateResource::getUrl('view', ['record' => $this->getOwnerRecord()]));
    }
}
