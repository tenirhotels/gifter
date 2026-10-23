<?php

namespace App\Filament\Pages;

use App\Enums\Permission;
use App\Exceptions\DomainRuleViolation;
use App\Filament\Resources\Services\ServiceResource;
use App\Filament\Support\TranslatesPageLabels;
use App\Models\PmsService;
use App\Models\Service;
use App\Services\Pms\PmsCatalogSync;
use App\Services\Pms\PmsException;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use UnitEnum;

/**
 * Услуги из PMS (Exely): список обновляется раз в ночь и по кнопке. «Создать услугу из этой» открывает общую форму создания услуги
 * (название подставляется, цена вводится там же). Ключ — ID услуги в PMS; названия могут быть на разных языках.
 */
class PmsServices extends Page implements HasTable
{
    use InteractsWithTable;
    use TranslatesPageLabels;

    protected string $view = 'filament.pages.table-page';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowPath;

    protected static string|UnitEnum|null $navigationGroup = 'Каталог';

    protected static ?int $navigationSort = 5;

    protected static ?string $navigationLabel = 'Услуги PMS';

    protected static ?string $title = 'Услуги из PMS';

    protected static ?string $slug = 'pms-services';

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->can(Permission::ViewCatalog);
    }

    public function getSubheading(): string|Htmlable|null
    {
        $last = PmsCatalogSync::last();
        if (! $last) {
            return __('Список ещё не обновлялся.');
        }
        $when = now()->parse($last['at'])->format('d.m.Y H:i');

        return $last['ok'] ? __('Обновлено').": {$when} · ".__('новых').": {$last['stats']['new']}, ".__('изменено').": {$last['stats']['updated']}, ".__('пропало').": {$last['stats']['missing']} · ".PmsCatalogSync::languagesNote($last['stats'])
            : __('Последняя попытка').": {$when} — {$last['message']}";
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('sync')->label('Обновить услуги из PMS')->icon('heroicon-o-arrow-path')
                ->authorize(Permission::ManageCatalog)
                ->action(function () {
                    try {
                        $stats = app(PmsCatalogSync::class)->run();
                        Notification::make()->success()->title(__('Услуг в PMS: :p1, новых: :p2, изменено: :p3, пропало: :p4', ['p1' => $stats['total'], 'p2' => $stats['new'], 'p3' => $stats['updated'], 'p4' => $stats['missing']]).'. '.PmsCatalogSync::languagesNote($stats))->send();
                    } catch (PmsException $e) {
                        Notification::make()->danger()->title($e->getMessage())->send();
                    }
                }),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => PmsService::query()->with('service'))
            ->columns([
                TextColumn::make('name')->label('Название в PMS')->searchable()->sortable()
                    ->state(fn (PmsService $record) => $record->localizedName())
                    ->tooltip(fn (PmsService $record) => collect((array) $record->names)->map(fn ($n, $l) => strtoupper($l).': '.$n)->implode("\n") ?: null)
                    ->description(fn (PmsService $record) => 'ID '.$record->external_id.($record->kind ? ' · '.$record->kind : '')),
                IconColumn::make('allowed_in_certificate')->label('В сертификат')->boolean(),
                TextColumn::make('service.name')->label('Услуга в Gifter')->state(fn (PmsService $record) => $record->service?->localizedName())->placeholder(__('не заведена'))
                    ->url(fn (PmsService $record) => $record->service_id ? ServiceResource::getUrl('edit', ['record' => $record->service_id]) : null),
                TextColumn::make('missing_since')->label('Статус в PMS')->badge()
                    ->state(fn (PmsService $record) => $record->isMissing() ? __('Пропала в PMS') : ($record->status ?: __('Есть')))
                    ->color(fn (PmsService $record) => $record->isMissing() ? 'danger' : 'gray'),
                TextColumn::make('synced_at')->label('Обновлено')->dateTime('d.m.Y H:i')->placeholder('—'),
            ])
            ->filters([
                TernaryFilter::make('allowed_in_certificate')->label('В сертификат'),
            ])
            ->recordActions([
                Action::make('create')->label('Создать услугу из этой')->icon('heroicon-o-plus')
                    ->authorize(Permission::ManageCatalog)
                    ->visible(fn (PmsService $record) => ! $record->service_id && ! $record->isMissing())
                    ->url(fn (PmsService $record) => ServiceResource::getUrl('create', ['pms' => $record->external_id])),
                Action::make('link')->label('Связать с услугой Gifter')->icon('heroicon-o-link')->color('gray')
                    ->authorize(Permission::ManageCatalog)
                    ->visible(fn (PmsService $record) => ! $record->service_id && ! $record->isMissing())
                    ->modalDescription(__('Если такая услуга уже есть в Gifter — свяжите её, чтобы не создавать дубль.'))
                    ->schema([Select::make('service_id')->label('Услуга Gifter')->required()->searchable()
                        ->options(fn () => Service::query()->whereDoesntHave('pmsService')->orderBy('name')->pluck('name', 'id'))])
                    ->action(function (PmsService $record, array $data, Action $action) {
                        try {
                            app(PmsCatalogSync::class)->link($record, Service::findOrFail($data['service_id']));
                            Notification::make()->success()->title(__('Услуга связана'))->send();
                        } catch (DomainRuleViolation $e) {
                            Notification::make()->danger()->title($e->getMessage())->send();
                            $action->halt();
                        }
                    }),
            ])
            ->defaultSort('name')
            ->emptyStateHeading(__('Услуги ещё не загружены'))
            ->emptyStateDescription(__('Нажмите «Обновить услуги из PMS». Нужны подключение Exely и доступ ключей к списку услуг.'));
    }
}
