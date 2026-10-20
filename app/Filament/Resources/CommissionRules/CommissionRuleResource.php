<?php

namespace App\Filament\Resources\CommissionRules;

use App\Enums\CommissionType;
use App\Filament\Resources\CommissionRules\Pages\CreateCommissionRule;
use App\Filament\Resources\CommissionRules\Pages\ListCommissionRules;
use App\Filament\Support\TranslatesResourceLabels;
use App\Models\CommissionRule;
use App\Models\Package;
use BackedEnum;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * Правила комиссии продавцов по пакетам. Каждое изменение — новая версия на период;
 * прежние версии не правятся и не удаляются, сертификаты хранят свой снимок.
 */
class CommissionRuleResource extends Resource
{
    use TranslatesResourceLabels;

    protected static ?string $model = CommissionRule::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedReceiptPercent;

    protected static string|UnitEnum|null $navigationGroup = 'Администрирование';

    protected static ?int $navigationSort = 20;

    protected static ?string $navigationLabel = 'Комиссии за продажи';

    protected static ?string $modelLabel = 'правило комиссии';

    protected static ?string $pluralModelLabel = 'Правила комиссии';

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
            Select::make('package_id')->label('Пакет')->required()->searchable()
                ->options(fn () => Package::query()->where('is_complimentary', false)->orderBy('name')->pluck('name', 'id'))
                ->helperText(__('Бесплатные пакеты комиссию не дают.')),
            Select::make('type')->label('Вид комиссии')->options(CommissionType::class)->required()->live()->default(CommissionType::Percent->value),
            // Процент — по умолчанию (пока вид не выбран или выбран «процент»), сумма — только при «фиксированной».
            TextInput::make('rate')->label('Процент от цены продажи')->numeric()->minValue(0.01)->maxValue(100)->suffix('%')->dehydratedWhenHidden()
                ->visible(fn (Get $get) => ! self::isFixed($get))
                ->required(fn (Get $get) => ! self::isFixed($get)),
            TextInput::make('fixed_amount')->label('Сумма за сертификат')->numeric()->minValue(1)->suffix('₸')->dehydratedWhenHidden()
                ->visible(fn (Get $get) => self::isFixed($get))
                ->required(fn (Get $get) => self::isFixed($get)),
            DatePicker::make('valid_from')->label('Действует с')->required()->default(today())
                ->helperText(__('Правило применяется по дате продажи сертификата. Открытое прежнее правило закроется днём раньше.')),
            DatePicker::make('valid_until')->label('Действует до')->helperText(__('Пусто — без окончания.')),
        ])->columns(2);
    }

    private static function isFixed(Get $get): bool
    {
        $type = $get('type');

        return ($type instanceof CommissionType ? $type->value : $type) === CommissionType::Fixed->value;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('package.name')->label('Пакет')->searchable()->sortable(),
                TextColumn::make('type')->label('Вид')->badge(),
                TextColumn::make('value')->label('Комиссия')->state(fn (CommissionRule $record) => $record->describe())->weight('bold'),
                TextColumn::make('valid_from')->label('С')->date('d.m.Y')->sortable(),
                TextColumn::make('valid_until')->label('До')->date('d.m.Y')->placeholder(__('без окончания')),
                TextColumn::make('state')->label('Состояние')->badge()
                    ->state(fn (CommissionRule $record) => $record->valid_from->isFuture() ? __('Ещё не действует')
                        : ($record->valid_until && $record->valid_until->isPast() ? __('Архив') : __('Действует')))
                    ->color(fn (string $state) => match ($state) {
                        __('Действует') => 'success', __('Архив') => 'gray', default => 'info'
                    }),
                TextColumn::make('commissions_count')->label('Сертификатов')->counts('commissions'),
                TextColumn::make('creator.name')->label('Кто добавил')->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([SelectFilter::make('package_id')->label('Пакет')->relationship('package', 'name')])
            ->defaultSort('valid_from', 'desc')
            ->emptyStateHeading(__('Правил комиссии нет — продавцам пока ничего не начисляется'))
            ->emptyStateDescription(__('Добавьте правило для пакета: процент или фиксированную сумму за сертификат.'));
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCommissionRules::route('/'),
            'create' => CreateCommissionRule::route('/create'),
        ];
    }
}
