<?php

namespace App\Filament\Resources\Certificates\RelationManagers;

use App\Enums\Permission;
use App\Filament\Resources\Certificates\CertificateResource;
use App\Filament\Support\RunsDomainActions;
use App\Models\Certificate;
use App\Models\CertificateItem;
use App\Models\Service;
use App\Services\CertificateManager;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** Состав сертификата. Правится только в черновике без открытых счетов. */
class ItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'items';

    protected static ?string $title = 'Состав сертификата';

    public function table(Table $table): Table
    {
        // Состав черновика из группы общий — меняется только всей группой.
        $editable = fn (): bool => $this->certificate()->isDraft() && ! $this->certificate()->isComplimentaryApproved() && ! $this->certificate()->batch_id;

        return $table
            ->description('Состав пакета не меняется. Допуслуги можно добавлять и убирать, пока сертификат — черновик; в бесплатном их может оплатить заказчик.')
            ->columns([
                TextColumn::make('service_name_snapshot')->label('Услуга')
                    ->description(fn (CertificateItem $record) => $record->from_package ? 'из пакета' : 'доп. услуга'),
                TextColumn::make('paid_by_customer')->label('Оплата')->badge()
                    ->state(fn (CertificateItem $record) => $record->paid_by_customer ? 'Платно' : 'Бесплатно')
                    ->color(fn (CertificateItem $record) => $record->paid_by_customer ? 'warning' : 'success')
                    ->visible(fn () => $this->certificate()->isComplimentary()),
                TextColumn::make('quantity')->label('Кол-во'),
                TextColumn::make('unit_snapshot')->label('Единица'),
                TextColumn::make('unit_price')->label('Цена')->formatStateUsing(fn ($state) => Money::format($state)),
                TextColumn::make('line_total')->label('Сумма')->formatStateUsing(fn ($state) => Money::format($state)),
                TextColumn::make('line_cost')->label('Себестоимость')->placeholder('не указана')
                    ->formatStateUsing(fn ($state) => Money::format($state))
                    ->visible(fn () => auth()->user()?->can(Permission::ViewCosts)),
                TextColumn::make('provider.name')->label('Поставщик')->placeholder('—')->toggleable(),
                TextColumn::make('fulfillment')->label('Исполнение')->state(fn (CertificateItem $record) => $record->requires_fulfillment
                    ? collect(['подготовка', $record->requires_scheduling ? 'время' : null, $record->requires_confirmation ? 'подтверждение' : null])->filter()->implode(', ')
                    : 'не требуется')->color(fn (CertificateItem $record) => $record->requires_fulfillment ? 'warning' : 'gray')->toggleable(),
                TextColumn::make('priceVersion.valid_from')->label('Цена с')->date('d.m.Y')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->paginated(false)
            ->headerActions([
                Action::make('addItem')->label('Добавить допуслугу')->icon('heroicon-o-plus')->visible($editable)->authorize(Permission::ComposeDrafts)
                    ->schema([
                        Select::make('service_id')->label('Услуга')->required()->searchable()->preload()
                            ->options(fn () => Service::where('is_active', true)->orderBy('name')->pluck('name', 'id')),
                        TextInput::make('quantity')->label('Количество')->integer()->minValue(1)->default(1)->required(),
                        Toggle::make('paid')->label('Платная — оплачивает заказчик')->default(false)
                            ->helperText('Выключено — бесплатно (маркетинговый расход). Включено — оплачивает заказчик, войдёт в счёт.')
                            ->visible(fn () => $this->certificate()->isComplimentary()),
                    ])
                    ->action(function (array $data, Action $action) {
                        RunsDomainActions::run($action, fn () => app(CertificateManager::class)
                            ->addItem($this->certificate(), Service::findOrFail($data['service_id']), (int) $data['quantity'], paid: (bool) ($data['paid'] ?? false)), 'Услуга добавлена');
                        $this->refreshPage();
                    }),
            ])
            ->recordActions([
                Action::make('togglePaid')
                    ->label(fn (CertificateItem $record) => $record->paid_by_customer ? 'Сделать бесплатной' : 'Сделать платной')
                    ->icon('heroicon-o-currency-dollar')->color('gray')
                    ->visible(fn (CertificateItem $record) => $editable() && ! $record->from_package && $this->certificate()->isComplimentary())
                    ->authorize(Permission::ComposeDrafts)
                    ->action(function (CertificateItem $record, Action $action) {
                        RunsDomainActions::run($action, fn () => app(CertificateManager::class)->setItemPaid($record, ! $record->paid_by_customer), 'Оплата услуги изменена');
                        $this->refreshPage();
                    }),
                Action::make('remove')->label('Убрать')->icon('heroicon-o-x-mark')->color('danger')
                    ->visible(fn (CertificateItem $record) => $editable() && ! $record->from_package)
                    ->authorize(Permission::ComposeDrafts)
                    ->requiresConfirmation()
                    ->action(function (CertificateItem $record, Action $action) {
                        RunsDomainActions::run($action, fn () => app(CertificateManager::class)->removeItem($record), 'Услуга убрана');
                        $this->refreshPage();
                    }),
            ]);
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    private function certificate(): Certificate
    {
        return $this->getOwnerRecord()->refresh();
    }

    /** Итоги и статус выводятся на странице выше — перезагружаем её целиком. */
    private function refreshPage(): void
    {
        $this->redirect(CertificateResource::getUrl('view', ['record' => $this->getOwnerRecord()]));
    }
}
