<?php

namespace App\Filament\Resources\Fulfillments;

use App\Enums\FulfillmentStatus;
use App\Enums\Permission;
use App\Filament\Resources\Certificates\CertificateResource;
use App\Filament\Support\FulfillmentActions;
use App\Filament\Support\TranslatesResourceLabels;
use App\Models\Provider;
use App\Models\ServiceFulfillment;
use App\Policies\CertificatePolicy;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/** Очередь заявок на исполнение услуг для Front Office и менеджера продаж. */
class ServiceFulfillmentResource extends Resource
{
    use TranslatesResourceLabels;

    protected static ?string $model = ServiceFulfillment::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static string|UnitEnum|null $navigationGroup = 'Продажи';

    protected static ?int $navigationSort = 4;

    protected static ?string $modelLabel = 'заявка на исполнение';

    protected static ?string $pluralModelLabel = 'Исполнение услуг';

    protected static ?string $navigationLabel = 'Исполнение услуг';

    protected static ?string $breadcrumb = 'Исполнение услуг';

    protected static ?string $slug = 'fulfillments';

    public static function canViewAny(): bool
    {
        return (bool) auth()->user()?->can(Permission::ManageFulfillments);
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

    /** Видимость — по сертификатам: продавец видел бы только свои (сейчас раздел у Front Office и менеджера — все). */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->whereHas('certificate', fn (Builder $q) => CertificatePolicy::scopeVisible($q, auth()->user()));
    }

    public static function getNavigationBadge(): ?string
    {
        $count = static::getEloquentQuery()->where('status', FulfillmentStatus::Pending)->count();

        return $count ? (string) $count : null;
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return __('Ждут отправки поставщику');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['item', 'provider', 'certificate.recipient', 'updater']))
            ->columns([
                TextColumn::make('certificate.number')->label('Сертификат')->searchable()
                    ->url(fn (ServiceFulfillment $record) => CertificateResource::getUrl('view', ['record' => $record->certificate_id]))
                    ->description(fn (ServiceFulfillment $record) => $record->certificate->status->getLabel()),
                TextColumn::make('certificate.recipient.full_name')->label('Гость')->placeholder(__('не указан'))->searchable(),
                ...FulfillmentActions::columns(),
            ])
            ->filters([
                SelectFilter::make('status')->label('Статус')->options(FulfillmentStatus::class)->multiple()
                    ->default(array_map(fn (FulfillmentStatus $s) => $s->value, FulfillmentStatus::open())),
                Filter::make('needs_action')->label('Требует действия')
                    ->query(fn (Builder $query) => $query->where(fn (Builder $q) => $q
                        ->where('status', FulfillmentStatus::Pending)
                        ->orWhere(fn (Builder $q) => $q->where('requires_confirmation', true)->whereIn('status', [FulfillmentStatus::Requested, FulfillmentStatus::Scheduled]))
                        ->orWhere(fn (Builder $q) => $q->where('requires_scheduling', true)->whereNull('scheduled_date')->whereIn('status', FulfillmentStatus::open())))),
                SelectFilter::make('when')->label('Когда')
                    ->options(['today' => __('Сегодня'), 'tomorrow' => __('Завтра'), 'week' => __('Ближайшие 7 дней'), 'none' => __('Дата не назначена')])
                    ->query(fn (Builder $query, array $data) => match ($data['value'] ?? null) {
                        'today' => $query->whereDate('scheduled_date', today()->toDateString()),
                        'tomorrow' => $query->whereDate('scheduled_date', today()->addDay()->toDateString()),
                        'week' => $query->whereBetween('scheduled_date', [today()->toDateString(), today()->addDays(7)->toDateString()]),
                        'none' => $query->whereNull('scheduled_date'),
                        default => $query,
                    }),
                SelectFilter::make('provider_id')->label('Поставщик')->options(fn () => Provider::query()->orderBy('name')->pluck('name', 'id'))->searchable(),
            ])
            ->recordActions(FulfillmentActions::make())
            ->defaultSort(fn (Builder $query) => $query->orderByRaw('scheduled_date is null')->orderBy('scheduled_date')->orderBy('scheduled_time')->orderBy('id'));
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListServiceFulfillments::route('/')];
    }
}
