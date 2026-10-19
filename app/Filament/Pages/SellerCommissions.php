<?php

namespace App\Filament\Pages;

use App\Enums\CommissionStatus;
use App\Enums\Permission;
use App\Filament\Resources\Certificates\CertificateResource;
use App\Filament\Support\CommissionPeriod;
use App\Filament\Support\RunsDomainActions;
use App\Models\CertificateCommission;
use App\Models\User;
use App\Services\CommissionManager;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Component;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

/**
 * Комиссии одного продавца по сертификатам: из чего сложена сумма, согласование (директор),
 * выплата и её отмена (Финансы). Продавец видит здесь только себя.
 */
class SellerCommissions extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.pages.table-page';

    protected static ?string $slug = 'commissions/seller/{seller}';

    protected static bool $shouldRegisterNavigation = false;

    public ?User $sellerModel = null;

    public function mount(int|string $seller): void
    {
        $this->sellerModel = User::findOrFail($seller);
    }

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->can(Permission::ViewAllCommissions);
    }

    public function getTitle(): string|Htmlable
    {
        return 'Комиссии: '.$this->sellerModel->name;
    }

    public function getBreadcrumbs(): array
    {
        return [Commissions::getUrl() => 'Комиссии', $this->sellerModel->name];
    }

    public function table(Table $table): Table
    {
        $manager = fn (): CommissionManager => app(CommissionManager::class);
        $report = function (array $result, string $done) {
            $note = Notification::make()->title("{$done}: {$result['done']}");
            $result['errors'] ? $note->warning()->body(implode("\n", array_slice($result['errors'], 0, 5))) : $note->success();
            $note->send();
        };

        return $table
            ->query(fn () => CertificateCommission::query()->where('seller_id', $this->sellerModel->id)->with(['certificate.package', 'payments', 'rule', 'approver']))
            ->columns([
                TextColumn::make('certificate.number')->label('Сертификат')->searchable()
                    ->url(fn (CertificateCommission $record) => CertificateResource::getUrl('view', ['record' => $record->certificate_id]))
                    ->description(fn (CertificateCommission $record) => $record->certificate->package?->name),
                TextColumn::make('certificate.status')->label('Статус сертификата')->badge(),
                TextColumn::make('sale_price')->label('Цена продажи')->formatStateUsing(fn ($state) => Money::format($state)),
                TextColumn::make('rule')->label('Правило')->state(fn (CertificateCommission $record) => $record->ruleLabel())
                    ->description(fn (CertificateCommission $record) => $record->rule ? 'с '.$record->rule->valid_from->format('d.m.Y') : null),
                TextColumn::make('amount')->label('Комиссия')->formatStateUsing(fn ($state) => Money::format($state))->weight('bold')->sortable()
                    ->summarize(Sum::make()->label('Итого')->formatStateUsing(fn ($state) => Money::format($state))),
                TextColumn::make('status')->label('Статус')->badge()
                    ->description(fn (CertificateCommission $record) => $record->void_reason),
                TextColumn::make('earned_at')->label('Начислена')->dateTime('d.m.Y')->placeholder('—')->sortable(),
                TextColumn::make('approved_at')->label('Согласована')->dateTime('d.m.Y')->placeholder('—')
                    ->description(fn (CertificateCommission $record) => $record->approver?->name),
                TextColumn::make('payment')->label('Выплата')->placeholder('—')
                    ->state(fn (CertificateCommission $record) => ($p = $record->activePayment()) ? $p->paid_at->format('d.m.Y') : null)
                    ->description(fn (CertificateCommission $record) => ($p = $record->activePayment())
                        ? trim(($p->payer?->name ?? '').' '.($p->reference ? "· {$p->reference}" : '')) : null),
            ])
            ->filters([
                CommissionPeriod::filter(),
                SelectFilter::make('status')->label('Статус')->options(CommissionStatus::class)->multiple(),
            ])
            ->recordActions([
                Action::make('approve')->label('Согласовать')->icon('heroicon-o-hand-thumb-up')->color('success')
                    ->authorize(Permission::ApproveCommissions)
                    ->visible(fn (CertificateCommission $record) => $record->status === CommissionStatus::Earned)
                    ->requiresConfirmation()
                    ->action(fn (CertificateCommission $record, Action $action) => RunsDomainActions::run($action,
                        fn () => $manager()->approve($record), 'Комиссия согласована к выплате')),
                Action::make('pay')->label('Выплачено')->icon('heroicon-o-banknotes')->color('success')
                    ->authorize(Permission::PayCommissions)
                    ->visible(fn (CertificateCommission $record) => $record->status === CommissionStatus::Approved)
                    ->modalDescription(fn (CertificateCommission $record) => 'Сумма '.Money::format($record->amount).' · '.$record->certificate->number)
                    ->schema(self::paymentFields())
                    ->action(fn (CertificateCommission $record, array $data, Action $action) => RunsDomainActions::run($action,
                        fn () => $manager()->pay($record, Carbon::parse($data['paid_at']), $data['reference'] ?? null, $data['comment'] ?? null), 'Выплата отмечена')),
                Action::make('unapprove')->label('Снять согласование')->icon('heroicon-o-arrow-uturn-left')->color('gray')
                    ->authorize(Permission::ApproveCommissions)
                    ->visible(fn (CertificateCommission $record) => $record->status === CommissionStatus::Approved)
                    ->schema([Textarea::make('reason')->label('Причина')->required()])
                    ->action(fn (CertificateCommission $record, array $data, Action $action) => RunsDomainActions::run($action,
                        fn () => $manager()->unapprove($record, $data['reason']), 'Согласование снято')),
                Action::make('reverse')->label('Отменить выплату')->icon('heroicon-o-x-mark')->color('danger')
                    ->authorize(Permission::PayCommissions)
                    ->visible(fn (CertificateCommission $record) => $record->status === CommissionStatus::Paid)
                    ->modalDescription('Запись выплаты сохранится с причиной отмены; комиссия вернётся в «Согласована к выплате».')
                    ->schema([Textarea::make('reason')->label('Причина')->required()])
                    ->action(fn (CertificateCommission $record, array $data, Action $action) => RunsDomainActions::run($action,
                        fn () => $manager()->reversePayment($record, $data['reason']), 'Выплата отменена')),
            ])
            ->toolbarActions([
                BulkAction::make('approveSelected')->label('Согласовать выбранные')->icon('heroicon-o-hand-thumb-up')->color('success')
                    ->authorize(Permission::ApproveCommissions)->deselectRecordsAfterCompletion()->requiresConfirmation()
                    ->action(fn (Collection $records) => $report($manager()->each($records, fn ($c) => $manager()->approve($c)), 'Согласовано')),
                BulkAction::make('paySelected')->label('Отметить выплату')->icon('heroicon-o-banknotes')->color('success')
                    ->authorize(Permission::PayCommissions)->deselectRecordsAfterCompletion()
                    ->modalDescription('Одна дата и один номер или комментарий на все выбранные комиссии.')
                    ->schema(self::paymentFields())
                    ->action(fn (Collection $records, array $data) => $report($manager()->each($records, fn ($c) => $manager()->pay($c,
                        Carbon::parse($data['paid_at']), $data['reference'] ?? null, $data['comment'] ?? null)), 'Выплачено')),
            ])
            ->defaultSort('earned_at', 'desc')
            ->emptyStateHeading('По этому продавцу комиссий пока нет');
    }

    /** @return list<Component> */
    private static function paymentFields(): array
    {
        return [
            DateTimePicker::make('paid_at')->label('Дата выплаты')->default(now())->maxDate(now())->required(),
            TextInput::make('reference')->label('Номер платежа / ведомости')->maxLength(255),
            Textarea::make('comment')->label('Комментарий'),
        ];
    }
}
