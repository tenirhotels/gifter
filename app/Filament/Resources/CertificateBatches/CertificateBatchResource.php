<?php

namespace App\Filament\Resources\CertificateBatches;

use App\Enums\BatchStatus;
use App\Enums\ComplimentaryPurpose;
use App\Enums\Permission;
use App\Filament\Resources\Customers\Schemas\CustomerForm;
use App\Filament\Support\ValidityFields;
use App\Models\CertificateBatch;
use App\Models\Customer;
use App\Models\Package;
use App\Services\CertificateBatchManager;
use App\Support\Money;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/** Блоки бесплатных сертификатов: массовый выпуск для инфлюенсеров, партнёров, бартера и др. */
class CertificateBatchResource extends Resource
{
    protected static ?string $model = CertificateBatch::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static string|UnitEnum|null $navigationGroup = 'Продажи';

    protected static ?int $navigationSort = 2;

    protected static ?string $modelLabel = 'блок бесплатных';

    protected static ?string $pluralModelLabel = 'Бесплатные блоками';

    protected static ?string $navigationLabel = 'Бесплатные блоками';

    protected static ?string $breadcrumb = 'Бесплатные блоками';

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

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('package_id')->label('Бесплатный пакет')->required()->live()
                ->relationship('package', 'name', fn ($query) => $query->where('is_active', true)->where('is_complimentary', true))->preload()
                ->afterStateUpdated(fn ($state, $set) => $set('purpose', Package::find($state)?->complimentary_purpose?->value)),
            TextInput::make('quantity')->label('Количество сертификатов')->integer()->minValue(1)->maxValue(CertificateBatchManager::MAX_QUANTITY)->required()->default(10),
            Select::make('purpose')->label('Цель')->options(ComplimentaryPurpose::class)->required()->live()
                ->helperText(fn ($state) => ($state instanceof ComplimentaryPurpose ? $state : ComplimentaryPurpose::tryFrom((string) $state))?->getDescription()),
            Select::make('customer_id')->label('Партнёр (необязательно)')
                ->relationship('customer', 'full_name')->searchable(['last_name', 'first_name', 'company', 'bin'])->preload()
                ->getOptionLabelFromRecordUsing(fn (Customer $record) => $record->displayName())
                ->createOptionForm(CustomerForm::fields())
                ->helperText('Например, компания-партнёр по бартеру. Блогерам и гостям получателей указывают потом в каждом сертификате.'),
            Textarea::make('reason')->label('Основание (кому и зачем)')->required()->columnSpanFull(),
            ValidityFields::make(),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->columns(3)->components([
            Section::make('Блок')->columns(3)->columnSpan(2)->schema([
                TextEntry::make('id')->label('Номер блока')->formatStateUsing(fn ($state) => "№{$state}"),
                TextEntry::make('status')->label('Статус')->badge(),
                TextEntry::make('purpose')->label('Цель'),
                TextEntry::make('package.name')->label('Пакет')->helperText(fn (CertificateBatch $record) => $record->package?->validityLabel()),
                TextEntry::make('quantity')->label('Сертификатов'),
                TextEntry::make('validity')->label('Срок действия')
                    ->state(fn (CertificateBatch $record) => $record->valid_from && $record->valid_until
                        ? $record->valid_from->format('d.m.Y').' — '.$record->valid_until->format('d.m.Y') : 'по пакету'),
                TextEntry::make('customer.full_name')->label('Партнёр')->placeholder('—')
                    ->state(fn (CertificateBatch $record) => $record->customer?->displayName()),
                TextEntry::make('reason')->label('Основание')->columnSpanFull(),
            ]),
            Section::make('Согласование')->columnSpan(1)->schema([
                TextEntry::make('creator.name')->label('Создал')->helperText(fn (CertificateBatch $record) => $record->created_at?->format('d.m.Y H:i')),
                TextEntry::make('approver.name')->label('Согласовал директор')->placeholder('ждёт согласования')
                    ->helperText(fn (CertificateBatch $record) => $record->approved_at?->format('d.m.Y H:i')),
                TextEntry::make('issued_at')->label('Выпущен')->dateTime('d.m.Y H:i')->placeholder('ещё не выпущен'),
                TextEntry::make('marketing_cost')->label('Маркетинговый расход (себестоимость)')
                    ->state(fn (CertificateBatch $record) => Money::format($record->certificates()->sum('total_cost')))
                    ->visible(fn () => auth()->user()?->can(Permission::ViewCosts)),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['package', 'creator', 'customer']))
            ->columns([
                TextColumn::make('id')->label('Блок')->formatStateUsing(fn ($state) => "№{$state}")->sortable(),
                TextColumn::make('status')->label('Статус')->badge(),
                TextColumn::make('purpose')->label('Цель'),
                TextColumn::make('package.name')->label('Пакет'),
                TextColumn::make('quantity')->label('Шт.'),
                TextColumn::make('reason')->label('Основание')->limit(60)->wrap(),
                TextColumn::make('creator.name')->label('Создал')->description(fn ($record) => $record->created_at?->format('d.m.Y')),
            ])
            ->filters([
                SelectFilter::make('status')->label('Статус')->options(BatchStatus::class),
                SelectFilter::make('purpose')->label('Цель')->options(ComplimentaryPurpose::class),
            ])
            ->recordActions([ViewAction::make()])
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
            'create' => Pages\CreateCertificateBatch::route('/create'),
            'view' => Pages\ViewCertificateBatch::route('/{record}'),
        ];
    }
}
