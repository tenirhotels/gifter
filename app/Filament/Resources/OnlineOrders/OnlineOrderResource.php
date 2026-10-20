<?php

namespace App\Filament\Resources\OnlineOrders;

use App\Enums\OnlineOrderStatus;
use App\Enums\Permission;
use App\Filament\Resources\Certificates\CertificateResource;
use App\Filament\Resources\OnlineOrders\Pages\ListOnlineOrders;
use App\Filament\Support\ContactFields;
use App\Filament\Support\RunsDomainActions;
use App\Filament\Support\TranslatesResourceLabels;
use App\Models\OnlineOrder;
use App\Services\OnlineOrderManager;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * «Заявки с сайта» — онлайн-заявки с публичной страницы. «Оформить» создаёт черновик сертификата
 * (продавец — оформивший сотрудник), дальше счёт Kaspi/Halyk и выпуск — как обычно.
 */
class OnlineOrderResource extends Resource
{
    use TranslatesResourceLabels;

    protected static ?string $model = OnlineOrder::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedGlobeAlt;

    protected static string|UnitEnum|null $navigationGroup = 'Продажи';

    protected static ?int $navigationSort = 2;

    protected static ?string $modelLabel = 'заявка с сайта';

    protected static ?string $pluralModelLabel = 'Заявки с сайта';

    protected static ?string $navigationLabel = 'Заявки с сайта';

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
        $count = OnlineOrder::query()->where('status', OnlineOrderStatus::New)->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function table(Table $table): Table
    {
        $manager = fn (): OnlineOrderManager => app(OnlineOrderManager::class);

        return $table
            ->columns([
                TextColumn::make('created_at')->label('Получена')->dateTime('d.m.Y H:i')->sortable(),
                TextColumn::make('package.name')->label('Пакет'),
                TextColumn::make('full_name')->label('Заказчик')->searchable()->weight('bold'),
                ...ContactFields::maskedColumns(),
                TextColumn::make('recipient_name')->label('Получатель')->placeholder('—')->toggleable(),
                TextColumn::make('delivery_method')->label('Получение')->badge()->color('gray')->toggleable(),
                TextColumn::make('gift_message')->label('Поздравление')->placeholder('—')->wrap()->limit(60)->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('notes')->label('Пожелания')->placeholder('—')->wrap()->limit(80)->toggleable(),
                TextColumn::make('status')->label('Статус')->badge()
                    ->description(fn (OnlineOrder $record) => $record->reject_reason),
                TextColumn::make('certificate.number')->label('Сертификат')->placeholder('—')
                    ->url(fn (OnlineOrder $record) => $record->certificate_id ? CertificateResource::getUrl('view', ['record' => $record->certificate_id]) : null),
            ])
            ->filters([
                SelectFilter::make('status')->label('Статус')->options(OnlineOrderStatus::class)->default(OnlineOrderStatus::New->value),
            ])
            ->recordActions([
                ContactFields::revealAction(label: 'Контакты'),
                Action::make('address')->label('Адрес доставки')->icon('heroicon-o-map-pin')->color('gray')
                    ->authorize(Permission::RevealContacts)
                    ->visible(fn (OnlineOrder $record) => filled($record->delivery_address))
                    ->mountUsing(function (OnlineOrder $record, Action $action) {
                        if (! ContactFields::logReveal($record)) {
                            Notification::make()->danger()->title(ContactFields::limitMessage())->send();
                            $action->halt();
                        }
                    })
                    ->modalDescription(fn (OnlineOrder $record) => $record->delivery_address)
                    ->modalSubmitAction(false)->modalCancelActionLabel(__('Закрыть')),
                Action::make('process')->label('Оформить')->icon('heroicon-o-check')->color('success')
                    ->authorize(Permission::ProcessOnlineOrders)
                    ->visible(fn (OnlineOrder $record) => $record->status === OnlineOrderStatus::New)
                    ->requiresConfirmation()
                    ->modalDescription(__('Будет создан черновик сертификата с вами в роли продавца. Дальше — счёт Kaspi или Halyk Bank и выпуск.'))
                    ->action(function (OnlineOrder $record, Action $action) use ($manager) {
                        $certificate = null;
                        RunsDomainActions::run($action, function () use ($manager, $record, &$certificate) {
                            $certificate = $manager()->process($record, auth()->user());
                        }, __('Черновик сертификата создан'));

                        return $certificate ? redirect(CertificateResource::getUrl('view', ['record' => $certificate])) : null;
                    }),
                Action::make('reject')->label('Отклонить')->icon('heroicon-o-x-mark')->color('danger')
                    ->authorize(Permission::ProcessOnlineOrders)
                    ->visible(fn (OnlineOrder $record) => $record->status === OnlineOrderStatus::New)
                    ->schema([Textarea::make('reason')->label('Причина отказа')->required()->minLength(5)->maxLength(500)])
                    ->action(fn (OnlineOrder $record, array $data, Action $action) => RunsDomainActions::run($action,
                        fn () => $manager()->reject($record, auth()->user(), $data['reason']), __('Заявка отклонена'))),
            ])
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading(__('Заявок нет'))
            ->emptyStateDescription(__('Они появятся, когда клиент оставит заявку на публичной странице.'));
    }

    public static function getPages(): array
    {
        return ['index' => ListOnlineOrders::route('/')];
    }
}
