<?php

namespace App\Filament\Resources\CertificateBatches;

use App\Enums\CertificateKind;
use App\Enums\Permission;
use App\Filament\Support\TranslatesResourceLabels;
use App\Models\CertificateBatch;
use App\Policies\CertificateBatchPolicy;
use App\Services\CertificateBatchManager;
use App\Support\Money;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Группа сертификатов — несколько сертификатов, оформленных вместе. Своего пункта меню нет:
 * создаётся мастером «Новый сертификат» (количество > 1), открывается
 * со страницы сертификата, из реестра и с главной.
 */
class CertificateBatchResource extends Resource
{
    use TranslatesResourceLabels;

    protected static ?string $model = CertificateBatch::class;

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $modelLabel = 'группа сертификатов';

    protected static ?string $pluralModelLabel = 'Группы сертификатов';

    protected static ?string $breadcrumb = 'Группы сертификатов';

    /** Включает название записи в строке навигации; само название — getRecordTitle(). */
    protected static ?string $recordTitleAttribute = 'id';

    /** «Группа №4» в строке навигации (крупного заголовка на странице нет). */
    public static function getRecordTitle(?Model $record): string
    {
        return $record instanceof CertificateBatch ? $record->shortLabel() : static::getModelLabel();
    }

    public static function getEloquentQuery(): Builder
    {
        return CertificateBatchPolicy::scopeVisible(parent::getEloquentQuery(), auth()->user());
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

    public static function infolist(Schema $schema): Schema
    {
        return $schema->columns(3)->components([
            Section::make('Группа сертификатов')->columns(3)->columnSpan(2)->schema([
                TextEntry::make('number')->label('Номер')->state(fn (CertificateBatch $record) => $record->shortLabel())->weight('bold'),
                TextEntry::make('status')->label('Статус')->badge()->formatStateUsing(fn ($state, CertificateBatch $record) => $record->statusLabel()),
                TextEntry::make('kind')->label('Тип')->badge()->formatStateUsing(fn ($state, CertificateBatch $record) => $record->kindLabel()),
                TextEntry::make('quantity')->label('Сертификатов'),
                TextEntry::make('package.name')->label('Пакет')->helperText(fn (CertificateBatch $record) => $record->package?->validityLabel()),
                TextEntry::make('validity')->label('Срок действия')
                    ->state(fn (CertificateBatch $record) => $record->valid_from && $record->valid_until
                        ? $record->valid_from->format('d.m.Y').' — '.$record->valid_until->format('d.m.Y').' (задан вручную)' : 'по пакету'),
                TextEntry::make('customer_name')->label('Заказчик')->placeholder('—')
                    ->state(fn (CertificateBatch $record) => $record->customer?->displayName()),
                TextEntry::make('purpose')->label('Цель')->visible(fn (CertificateBatch $record) => $record->isComplimentary()),
                TextEntry::make('reason')->label('Основание')->columnSpan(2)->visible(fn (CertificateBatch $record) => $record->isComplimentary()),
            ]),
            Section::make('Деньги и согласование')->columnSpan(1)->schema([
                TextEntry::make('total')->label(fn (CertificateBatch $record) => $record->isComplimentary() ? 'Стоимость по прайсу' : 'Стоимость группы')
                    ->state(fn (CertificateBatch $record) => Money::format($record->certificates()->sum('total_amount')))->weight('bold'),
                TextEntry::make('payable')->label('К оплате заказчиком')->weight('bold')
                    ->state(fn (CertificateBatch $record) => Money::format($record->certificates()->sum('payable_amount')))
                    ->helperText('Только платные допуслуги; остальное бесплатно.')
                    ->visible(fn (CertificateBatch $record) => $record->isComplimentary() && $record->certificates()->sum('payable_amount') > 0),
                TextEntry::make('invoice')->label('Счёт')
                    ->visible(fn (CertificateBatch $record) => ! $record->isComplimentary() || $record->certificates()->sum('payable_amount') > 0)
                    ->state(function (CertificateBatch $record) {
                        $line = $record->payments()->where('status', '!=', 'cancelled')->first();
                        if (! $line) {
                            return 'не выставлен';
                        }

                        return trim("{$line->method->getLabel()} ".($line->invoice_number ? "№ {$line->invoice_number}" : '')
                            .' · '.Money::format(app(CertificateBatchManager::class)->invoiceTotal($record)).' · '.$line->status->getLabel());
                    }),
                TextEntry::make('marketing_cost')->label('Маркетинговый расход (себестоимость)')
                    ->state(fn (CertificateBatch $record) => Money::format($record->certificates()->sum('marketing_cost')))
                    ->visible(fn (CertificateBatch $record) => $record->isComplimentary() && auth()->user()?->can(Permission::ViewCosts)),
                TextEntry::make('creator.name')->label('Оформил')->helperText(fn (CertificateBatch $record) => $record->created_at?->format('d.m.Y H:i')),
                TextEntry::make('approver.name')->label('Согласовал директор')->placeholder('ждёт согласования')
                    ->helperText(fn (CertificateBatch $record) => $record->approved_at?->format('d.m.Y H:i'))
                    ->visible(fn (CertificateBatch $record) => $record->isComplimentary()),
                TextEntry::make('issued_at')->label('Выпущена')->dateTime('d.m.Y H:i')->placeholder('ещё не выпущена'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')->label('Номер')->formatStateUsing(fn ($state, CertificateBatch $record) => $record->shortLabel()),
                TextColumn::make('kind')->label('Тип')->badge()->formatStateUsing(fn ($state, CertificateBatch $record) => $record->kindLabel()),
                TextColumn::make('status')->label('Статус')->badge()->formatStateUsing(fn ($state, CertificateBatch $record) => $record->statusLabel()),
                TextColumn::make('package.name')->label('Пакет'),
                TextColumn::make('quantity')->label('Шт.'),
                TextColumn::make('customer.full_name')->label('Заказчик')->placeholder('—'),
                TextColumn::make('created_at')->label('Оформлена')->date('d.m.Y'),
            ])
            ->filters([
                SelectFilter::make('kind')->label('Тип')->options([CertificateKind::Sale->value => 'Платная', CertificateKind::Complimentary->value => 'Бесплатная']),
                Filter::make('awaiting_approval')->label('Бесплатные: ждут согласования директора')
                    ->query(fn (Builder $query) => $query->awaitingApproval()),
                Filter::make('ready')->label('Можно выпускать')
                    ->query(fn (Builder $query) => $query->readyToIssue()),
            ])
            ->recordUrl(fn (CertificateBatch $record) => static::getUrl('view', ['record' => $record]))
            ->defaultSort('id', 'desc');
    }

    public static function getRelations(): array
    {
        return [RelationManagers\CertificatesRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCertificateBatches::route('/'),
            'view' => Pages\ViewCertificateBatch::route('/{record}'),
        ];
    }
}
