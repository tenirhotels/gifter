<?php

namespace App\Filament\Pages;

use App\Enums\Permission;
use App\Filament\Support\TranslatesPageLabels;
use App\Models\Service;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Сверка названий услуг по языкам: рус · қаз · eng в одной таблице, чего не хватает — подсвечено;
 * рядом — что пришло из PMS. Правка меняет переводы услуги Gifter (основное русское название — в карточке услуги).
 */
class ServiceTranslations extends Page implements HasTable
{
    use InteractsWithTable;
    use TranslatesPageLabels;

    protected string $view = 'filament.pages.table-page';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLanguage;

    protected static string|UnitEnum|null $navigationGroup = 'Каталог';

    protected static ?int $navigationSort = 6;

    protected static ?string $navigationLabel = 'Переводы услуг';

    protected static ?string $title = 'Переводы названий услуг';

    protected static ?string $slug = 'service-translations';

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->can(Permission::ViewCatalog);
    }

    /** missing — нет перевода; differs — отличается от присланного PMS; ok — всё заполнено. */
    private static function state(Service $service): string
    {
        $tr = (array) $service->name_translations;
        if (blank($tr['kk'] ?? null) || blank($tr['en'] ?? null)) {
            return 'missing';
        }
        $pms = (array) $service->pmsService?->names;
        foreach (['kk', 'en'] as $lang) {
            if (filled($pms[$lang] ?? null) && $pms[$lang] !== $tr[$lang]) {
                return 'differs';
            }
        }

        return 'ok';
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => Service::query()->with('pmsService'))
            ->columns([
                TextColumn::make('name')->label('Название (рус)')->searchable()->sortable()->wrap(),
                TextColumn::make('kk')->label('Название (қаз)')->wrap()->placeholder('—')
                    ->state(fn (Service $record) => $record->name_translations['kk'] ?? null)
                    ->description(fn (Service $record) => self::pmsHint($record, 'kk')),
                TextColumn::make('en')->label('Название (eng)')->wrap()->placeholder('—')
                    ->state(fn (Service $record) => $record->name_translations['en'] ?? null)
                    ->description(fn (Service $record) => self::pmsHint($record, 'en')),
                TextColumn::make('state')->label('Сверка')->badge()
                    ->state(fn (Service $record) => match (self::state($record)) {
                        'missing' => __('Нет перевода'), 'differs' => __('Отличается от PMS'), default => __('Заполнено'),
                    })
                    ->color(fn (Service $record) => match (self::state($record)) {
                        'missing' => 'danger', 'differs' => 'warning', default => 'success',
                    }),
            ])
            ->filters([
                SelectFilter::make('state')->label('Сверка')
                    ->options(['missing' => __('Нет перевода'), 'differs' => __('Отличается от PMS')])
                    ->query(function (Builder $query, array $data) {
                        if ($data['value'] ?? null) {
                            $ids = Service::query()->with('pmsService')->get()->filter(fn (Service $s) => self::state($s) === $data['value'])->pluck('id');
                            $query->whereIn('id', $ids);
                        }
                    }),
            ])
            ->recordActions([
                Action::make('edit')->label('Изменить')->icon('heroicon-o-pencil-square')
                    ->authorize(Permission::ManageCatalog)
                    ->fillForm(fn (Service $record) => ['kk' => $record->name_translations['kk'] ?? null, 'en' => $record->name_translations['en'] ?? null])
                    ->schema(fn (Service $record) => [
                        TextInput::make('kk')->label('Название (қаз)')->maxLength(255)
                            ->helperText(self::pmsHint($record, 'kk')),
                        TextInput::make('en')->label('Название (eng)')->maxLength(255)
                            ->helperText(self::pmsHint($record, 'en')),
                    ])
                    ->action(function (Service $record, array $data) {
                        $record->update(['name_translations' => ['kk' => $data['kk'] ?? null, 'en' => $data['en'] ?? null]]);
                        Notification::make()->success()->title(__('Переводы сохранены'))->send();
                    }),
            ])
            ->defaultSort('name')
            ->emptyStateHeading(__('Услуг пока нет'));
    }

    private static function pmsHint(Service $service, string $lang): ?string
    {
        $value = $service->pmsService?->names[$lang] ?? null;

        return filled($value) ? __('В PMS: :p1', ['p1' => $value]) : null;
    }
}
