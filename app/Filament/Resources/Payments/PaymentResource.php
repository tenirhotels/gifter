<?php

namespace App\Filament\Resources\Payments;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\Permission;
use App\Filament\Resources\Certificates\CertificateResource;
use App\Filament\Support\RunsDomainActions;
use App\Models\Payment;
use App\Services\PaymentManager;
use App\Support\Money;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use UnitEnum;

/** Реестр оплат для сверки Финансами. */
class PaymentResource extends Resource
{
    protected static ?string $model = Payment::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static string|UnitEnum|null $navigationGroup = 'Деньги';

    protected static ?int $navigationSort = 1;

    protected static ?string $modelLabel = 'оплата';

    protected static ?string $pluralModelLabel = 'Оплаты';

    public static function canViewAny(): bool
    {
        return auth()->user()?->can(Permission::ViewPaymentRegister) ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function getNavigationBadge(): ?string
    {
        if (! auth()->user()?->can(Permission::ReconcilePayments)) {
            return null;
        }
        $count = Payment::query()->where('status', PaymentStatus::Paid)->whereNull('reconciled_at')->count()
            + Payment::query()->where('status', PaymentStatus::Pending)->where('method', PaymentMethod::BankTransfer)->count();

        return $count ? (string) $count : null;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['certificate.customer', 'recorder', 'reconciler']))
            ->columns([
                TextColumn::make('certificate.number')->label('Сертификат')->searchable()
                    ->url(fn (Payment $record) => CertificateResource::getUrl('view', ['record' => $record->certificate_id])),
                TextColumn::make('certificate.customer.full_name')->label('Плательщик')->placeholder('—')->searchable(),
                TextColumn::make('method')->label('Способ'),
                TextColumn::make('amount')->label('Сумма')->formatStateUsing(fn ($state) => Money::format($state))->sortable(),
                TextColumn::make('status')->label('Статус')->badge(),
                TextColumn::make('invoice_number')->label('№ счёта')->placeholder('—')->searchable(),
                TextColumn::make('invoiced_at')->label('Выставлен')->dateTime('d.m.Y')->sortable(),
                TextColumn::make('paid_at')->label('Поступил')->dateTime('d.m.Y H:i')->placeholder('—')->sortable(),
                TextColumn::make('reconciled_at')->label('Сверено')->dateTime('d.m.Y')->placeholder('нет')
                    ->description(fn (Payment $record) => $record->reconciler?->name),
            ])
            ->filters([
                Filter::make('to_reconcile')->label('Ждут сверки')->default()
                    ->query(fn (Builder $query) => $query->where(fn (Builder $q) => $q
                        ->where(fn (Builder $q) => $q->where('status', PaymentStatus::Paid)->whereNull('reconciled_at'))
                        ->orWhere(fn (Builder $q) => $q->where('status', PaymentStatus::Pending)->where('method', PaymentMethod::BankTransfer)))),
                SelectFilter::make('method')->label('Способ')->options(PaymentMethod::class),
                SelectFilter::make('status')->label('Статус')->options(PaymentStatus::class),
            ])
            ->recordActions([
                Action::make('receiveTransfer')->label('Поступило на р/с')->icon('heroicon-o-banknotes')->color('success')
                    ->authorize(Permission::ReceiveBankTransfers)
                    ->visible(fn (Payment $record) => $record->status === PaymentStatus::Pending && $record->method === PaymentMethod::BankTransfer)
                    ->schema([DateTimePicker::make('paid_at')->label('Дата поступления по выписке')->default(now())->maxDate(now())->required()])
                    ->action(fn (Payment $record, array $data, Action $action) => RunsDomainActions::run($action,
                        fn () => app(PaymentManager::class)->markPaid($record, Carbon::parse($data['paid_at'])), 'Поступление отмечено и сверено')),
                Action::make('reconcile')->label('Сверено')->icon('heroicon-o-document-check')
                    ->authorize(Permission::ReconcilePayments)
                    ->visible(fn (Payment $record) => $record->status === PaymentStatus::Paid && ! $record->isReconciled())
                    ->requiresConfirmation()
                    ->action(fn (Payment $record, Action $action) => RunsDomainActions::run($action,
                        fn () => app(PaymentManager::class)->reconcile($record), 'Оплата сверена')),
            ])
            ->toolbarActions([
                BulkAction::make('reconcileSelected')->label('Сверить выбранные')->icon('heroicon-o-document-check')
                    ->authorize(Permission::ReconcilePayments)
                    ->requiresConfirmation()
                    ->action(function (Collection $records) {
                        $done = 0;
                        foreach ($records as $payment) {
                            if ($payment->status === PaymentStatus::Paid && ! $payment->isReconciled()) {
                                app(PaymentManager::class)->reconcile($payment);
                                $done++;
                            }
                        }
                        Notification::make()->success()->title("Сверено оплат: {$done}")->send();
                    })
                    ->deselectRecordsAfterCompletion(),
            ])
            ->defaultSort('id', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListPayments::route('/')];
    }
}
