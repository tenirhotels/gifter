<?php

namespace App\Filament\Resources\CertificateBatches;

use App\Enums\Permission;
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
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Покупка — несколько сертификатов в одной продаже. Своего пункта меню нет:
 * создаётся мастером «Новый сертификат» (количество > 1), открывается
 * со страницы сертификата, из реестра и с главной.
 */
class CertificateBatchResource extends Resource
{
    protected static ?string $model = CertificateBatch::class;

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $modelLabel = 'покупка';

    protected static ?string $pluralModelLabel = 'Покупки';

    protected static ?string $breadcrumb = 'Покупки';

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
            Section::make('Покупка')->columns(3)->columnSpan(2)->schema([
                TextEntry::make('status')->label('Статус')->badge()->formatStateUsing(fn ($state, CertificateBatch $record) => $record->statusLabel()),
                TextEntry::make('kind')->label('Тип')->badge(),
                TextEntry::make('quantity')->label('Сертификатов'),
                TextEntry::make('package.name')->label('Пакет')->helperText(fn (CertificateBatch $record) => $record->package?->validityLabel()),
                TextEntry::make('validity')->label('Срок действия')
                    ->state(fn (CertificateBatch $record) => $record->valid_from && $record->valid_until
                        ? $record->valid_from->format('d.m.Y').' — '.$record->valid_until->format('d.m.Y').' (задан вручную)' : 'по пакету'),
                TextEntry::make('customer_name')->label(fn (CertificateBatch $record) => $record->isComplimentary() ? 'Партнёр' : 'Покупатель')->placeholder('—')
                    ->state(fn (CertificateBatch $record) => $record->customer?->displayName()),
                TextEntry::make('purpose')->label('Цель')->visible(fn (CertificateBatch $record) => $record->isComplimentary()),
                TextEntry::make('reason')->label('Основание')->columnSpan(2)->visible(fn (CertificateBatch $record) => $record->isComplimentary()),
            ]),
            Section::make('Деньги и согласование')->columnSpan(1)->schema([
                TextEntry::make('total')->label(fn (CertificateBatch $record) => $record->isComplimentary() ? 'Стоимость по прайсу' : 'Стоимость покупки')
                    ->state(fn (CertificateBatch $record) => Money::format($record->certificates()->sum('total_amount')))->weight('bold'),
                TextEntry::make('invoice')->label('Счёт')
                    ->visible(fn (CertificateBatch $record) => ! $record->isComplimentary())
                    ->state(function (CertificateBatch $record) {
                        $line = $record->payments()->where('status', '!=', 'cancelled')->first();
                        if (! $line) {
                            return 'не выставлен';
                        }

                        return trim("{$line->method->getLabel()} ".($line->invoice_number ? "№ {$line->invoice_number}" : '')
                            .' · '.Money::format(app(CertificateBatchManager::class)->invoiceTotal($record)).' · '.$line->status->getLabel());
                    }),
                TextEntry::make('marketing_cost')->label('Маркетинговый расход (себестоимость)')
                    ->state(fn (CertificateBatch $record) => Money::format($record->certificates()->sum('total_cost')))
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
                TextColumn::make('id')->label('Покупка')->formatStateUsing(fn ($state) => "№{$state}"),
                TextColumn::make('status')->label('Статус')->badge()->formatStateUsing(fn ($state, CertificateBatch $record) => $record->statusLabel()),
                TextColumn::make('package.name')->label('Пакет'),
                TextColumn::make('quantity')->label('Шт.'),
                TextColumn::make('customer.full_name')->label('Покупатель')->placeholder('—'),
                TextColumn::make('created_at')->label('Оформлена')->date('d.m.Y'),
            ])
            ->filters([
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
