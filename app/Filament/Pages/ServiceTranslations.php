<?php

namespace App\Filament\Pages;

use App\Enums\Permission;
use App\Filament\Support\TranslatesPageLabels;
use App\Models\Package;
use App\Models\Service;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
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
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use UnitEnum;

/**
 * Все переводы каталога в одном месте: названия и описания услуг и пакетов, рус · қаз · eng; чего не хватает — подсвечено;
 * для услуг рядом — что пришло из PMS. Основные (русские) название и описание правятся в карточке.
 */
class ServiceTranslations extends Page implements HasTable
{
    use InteractsWithTable;
    use TranslatesPageLabels;

    protected string $view = 'filament.pages.table-page';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLanguage;

    protected static string|UnitEnum|null $navigationGroup = 'Продажи';

    protected static ?int $navigationSort = 4;

    protected static ?string $navigationLabel = 'Переводы';

    protected static ?string $title = 'Переводы услуг и пакетов';

    protected static ?string $slug = 'service-translations';

    /** Что показываем: services | packages. */
    public string $kind = 'services';

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->can(Permission::ViewCatalog);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('services')->label('Услуги')->color($this->kind === 'services' ? 'primary' : 'gray')
                ->action(fn () => $this->switchKind('services')),
            Action::make('packages')->label('Пакеты')->color($this->kind === 'packages' ? 'primary' : 'gray')
                ->action(fn () => $this->switchKind('packages')),
        ];
    }

    public function switchKind(string $kind): void
    {
        $this->kind = $kind === 'packages' ? 'packages' : 'services';
        $this->resetTable();
    }

    /** missing — нет перевода; differs — отличается от присланного PMS; ok — всё заполнено. */
    private static function state(Model $record): string
    {
        $name = (array) $record->name_translations;
        $description = (array) $record->description_translations;
        foreach (['kk', 'en'] as $lang) {
            if (blank($name[$lang] ?? null) || (filled($record->description) && blank($description[$lang] ?? null))) {
                return 'missing';
            }
        }
        $pms = $record instanceof Service ? (array) $record->pmsService?->names : [];
        foreach (['kk', 'en'] as $lang) {
            if (filled($pms[$lang] ?? null) && $pms[$lang] !== $name[$lang]) {
                return 'differs';
            }
        }

        return 'ok';
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => $this->kind === 'packages' ? Package::query() : Service::query()->with('pmsService'))
            ->columns([
                TextColumn::make('name')->label('Название (рус)')->searchable()->sortable()->wrap()
                    ->description(fn (Model $record) => filled($record->description) ? Str::limit((string) $record->description, 90) : null),
                TextColumn::make('kk')->label('қаз')->wrap()->placeholder('—')
                    ->state(fn (Model $record) => $record->name_translations['kk'] ?? null)
                    ->description(fn (Model $record) => self::descriptionPart($record, 'kk') ?? self::pmsHint($record, 'kk')),
                TextColumn::make('en')->label('eng')->wrap()->placeholder('—')
                    ->state(fn (Model $record) => $record->name_translations['en'] ?? null)
                    ->description(fn (Model $record) => self::descriptionPart($record, 'en') ?? self::pmsHint($record, 'en')),
                TextColumn::make('state')->label('Сверка')->badge()
                    ->state(fn (Model $record) => match (self::state($record)) {
                        'missing' => __('Нет перевода'), 'differs' => __('Отличается от PMS'), default => __('Заполнено'),
                    })
                    ->color(fn (Model $record) => match (self::state($record)) {
                        'missing' => 'danger', 'differs' => 'warning', default => 'success',
                    }),
            ])
            ->filters([
                SelectFilter::make('state')->label('Сверка')
                    ->options(['missing' => __('Нет перевода'), 'differs' => __('Отличается от PMS')])
                    ->query(function (Builder $query, array $data) {
                        if ($data['value'] ?? null) {
                            $ids = $query->getModel()->newQuery()->get()->filter(fn (Model $m) => self::state($m) === $data['value'])->pluck('id');
                            $query->whereIn('id', $ids);
                        }
                    }),
            ])
            ->recordActions([
                Action::make('edit')->label('Изменить')->icon('heroicon-o-pencil-square')
                    ->authorize(Permission::ManageCatalog)
                    ->modalHeading(fn (Model $record) => __('Переводы').': '.$record->name)
                    ->fillForm(fn (Model $record) => [
                        'src_name' => $record->name, 'src_desc' => $record->description,
                        'name_kk' => $record->name_translations['kk'] ?? null, 'name_en' => $record->name_translations['en'] ?? null,
                        'desc_kk' => $record->description_translations['kk'] ?? null, 'desc_en' => $record->description_translations['en'] ?? null,
                    ])
                    ->schema(fn (Model $record) => [
                        TextInput::make('src_name')->label('Название (рус)')->disabled()->dehydrated(false),
                        Textarea::make('src_desc')->label('Описание (рус)')->rows(3)->disabled()->dehydrated(false)->visible(filled($record->description)),
                        TextInput::make('name_kk')->label('Название (қаз)')->maxLength(255)->helperText(self::pmsHint($record, 'kk')),
                        TextInput::make('name_en')->label('Название (eng)')->maxLength(255)->helperText(self::pmsHint($record, 'en')),
                        Textarea::make('desc_kk')->label('Описание (қаз)')->rows(3)->visible(filled($record->description)),
                        Textarea::make('desc_en')->label('Описание (eng)')->rows(3)->visible(filled($record->description)),
                    ])
                    ->action(function (Model $record, array $data) {
                        $record->update([
                            'name_translations' => ['kk' => $data['name_kk'] ?? null, 'en' => $data['name_en'] ?? null],
                            'description_translations' => ['kk' => $data['desc_kk'] ?? null, 'en' => $data['desc_en'] ?? null],
                        ]);
                        Notification::make()->success()->title(__('Переводы сохранены'))->send();
                    }),
            ])
            ->defaultSort('name')
            ->emptyStateHeading(__('Записей пока нет'));
    }

    /** Фрагмент описания на языке, если он есть (подпись под названием). */
    private static function descriptionPart(Model $record, string $lang): ?string
    {
        $text = $record->description_translations[$lang] ?? null;

        return filled($text) ? Str::limit((string) $text, 90) : null;
    }

    private static function pmsHint(Model $record, string $lang): ?string
    {
        $value = $record instanceof Service ? ($record->pmsService?->names[$lang] ?? null) : null;

        return filled($value) ? __('В PMS: :p1', ['p1' => $value]) : null;
    }
}
