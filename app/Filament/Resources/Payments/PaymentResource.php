<?php

namespace App\Filament\Resources\Payments;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\Permission;
use App\Filament\Resources\Certificates\CertificateResource;
use App\Filament\Support\PaymentCorrectionActions;
use App\Filament\Support\RunsDomainActions;
use App\Filament\Support\TranslatesResourceLabels;
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
    use TranslatesResourceLabels;

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
            + Payment::query()->where('status', PaymentStatus::Pending)->whereIn('method', PaymentMethod::financeConfirmed())->count();

        return $count ? (string) $count : null;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['certificate.customer', 'batch', 'recorder', 'reconciler']))
            ->columns([
                TextColumn::make('certificate.number')->label('Сертификат')->searchable()
                    ->url(fn (Payment $record) => CertificateResource::getUrl('view', ['record' => $record->certificate_id]))
                    ->description(fn (Payment $record) => $record->batch_id ? "Группа №{$record->batch_id} (из {$record->batch->quantity})" : null),
                TextColumn::make('certificate.customer.full_name')->label('Плательщик')->placeholder('—')->searchable(),
                TextColumn::make('method')->label('Способ оплаты'),
                TextColumn::make('amount')->label('Сумма')->formatStateUsing(fn ($state) => Money::format($state))->sortable(),
                TextColumn::make('status')->label('Статус оплаты')->badge(),
                TextColumn::make('invoice_number')->label('Номер счёта')->placeholder('—')->searchable(),
                TextColumn::make('invoiced_at')->label('Дата выставления')->dateTime('d.m.Y')->sortable(),
                TextColumn::make('paid_at')->label('Дата оплаты')->dateTime('d.m.Y H:i')->placeholder('—')->sortable(),
                TextColumn::make('reconciled_at')->label('Сверка')->dateTime('d.m.Y')->placeholder('не сверено')
                    ->description(fn (Payment $record) => $record->reconciler?->name),
            ])
            ->filters([
                Filter::make('to_reconcile')->label('Ждут сверки')->default()
                    ->query(fn (Builder $query) => $query->where(fn (Builder $q) => $q
                        ->where(fn (Builder $q) => $q->where('status', PaymentStatus::Paid)->whereNull('reconciled_at'))
                        ->orWhere(fn (Builder $q) => $q->where('status', PaymentStatus::Pending)->whereIn('method', PaymentMethod::financeConfirmed())))),
                SelectFilter::make('method')->label('Способ оплаты')->options(PaymentMethod::class),
                SelectFilter::make('status')->label('Статус оплаты')->options(PaymentStatus::class),
            ])
            ->recordActions([
                Action::make('receiveTransfer')->label(fn (Payment $record) => $record->method === PaymentMethod::Cash ? 'Принято в кассе' : 'Поступило на р/с')->icon('heroicon-o-banknotes')->color('success')
                    ->authorize(Permission::ReceiveBankTransfers)
                    ->visible(fn (Payment $record) => $record->status === PaymentStatus::Pending && $record->method->requiresFinanceConfirmation())
                    ->modalDescription(fn (Payment $record) => $record->batch_id ? 'Общий счёт группы: отметка относится ко всем его строкам.' : null)
                    ->schema([DateTimePicker::make('paid_at')->label('Дата поступления (выписка или касса)')->default(now())->maxDate(now())->required()])
                    ->action(fn (Payment $record, array $data, Action $action) => RunsDomainActions::run($action,
                        fn () => app(PaymentManager::class)->markPaid($record, Carbon::parse($data['paid_at'])), 'Поступление отмечено и сверено')),
                Action::make('reconcile')->label('Сверка')->icon('heroicon-o-document-check')
                    ->authorize(Permission::ReconcilePayments)
                    ->visible(fn (Payment $record) => $record->status === PaymentStatus::Paid && ! $record->isReconciled())
                    ->requiresConfirmation()
                    ->modalDescription(fn (Payment $record) => $record->batch_id ? 'Общий счёт группы: сверяются все его строки.' : null)
                    ->action(fn (Payment $record, Action $action) => RunsDomainActions::run($action,
                        fn () => app(PaymentManager::class)->reconcile($record), 'Оплата сверена')),
                ...PaymentCorrectionActions::make(),
            ])
            ->toolbarActions([
                BulkAction::make('reconcileSelected')->label('Сверить выбранные')->icon('heroicon-o-document-check')
                    ->authorize(Permission::ReconcilePayments)
                    ->requiresConfirmation()
                    ->action(function (Collection $records) {
                        $done = 0;
                        foreach ($records as $payment) {
                            // Строки одного счёта группы сверяются вместе — уже сверенные пропускаем.
                            $payment->refresh();
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
