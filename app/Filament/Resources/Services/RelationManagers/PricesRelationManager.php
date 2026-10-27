<?php

namespace App\Filament\Resources\Services\RelationManagers;

use App\Enums\Permission;
use App\Filament\Support\RunsDomainActions;
use App\Models\Service;
use App\Models\ServicePrice;
use App\Services\ServicePricing;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Component;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Carbon;

/** Версии цен: только добавление новой версии, без правки и удаления. */
class PricesRelationManager extends RelationManager
{
    protected static string $relationship = 'prices';

    protected static ?string $title = 'Цены';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('valid_from')->label('С')->date('d.m.Y'),
                TextColumn::make('valid_until')->label('По')->date('d.m.Y')->placeholder(__('бессрочно')),
                TextColumn::make('cost')->label('Себестоимость')->placeholder(__('не указана'))
                    ->formatStateUsing(fn ($state) => Money::format($state))
                    ->visible(fn () => auth()->user()?->can(Permission::ViewCosts)),
                TextColumn::make('price')->label('Цена')->formatStateUsing(fn ($state) => Money::format($state)),
                TextColumn::make('approved_at')->label('Подтверждение')->badge()
                    ->state(fn (ServicePrice $record) => $record->isApproved() ? __('Действует') : __('Ждёт подтверждения администратора'))
                    ->color(fn (ServicePrice $record) => $record->isApproved() ? 'success' : 'warning'),
                TextColumn::make('creator.name')->label('Добавил')->placeholder('—'),
                TextColumn::make('created_at')->label('Добавлено')->dateTime('d.m.Y H:i'),
            ])
            ->defaultSort('valid_from', 'desc')
            ->description(__('Добавленную сегодня цену можно исправить, пока она не попала в сертификаты. Более ранние версии не меняются — добавьте новую.'))
            ->recordActions([
                Action::make('approvePrice')->label('Подтвердить цену')->icon('heroicon-o-check-badge')->color('success')
                    ->authorize(Permission::AdminCorrections)
                    ->visible(fn (ServicePrice $record) => ! $record->isApproved())
                    ->requiresConfirmation()
                    ->modalDescription(fn (ServicePrice $record) => __('Цена :p1 вступит в силу с :p2; предыдущая закроется днём раньше.', ['p1' => Money::format($record->price), 'p2' => $record->valid_from->format('d.m.Y')]))
                    ->action(fn (ServicePrice $record, Action $action) => RunsDomainActions::run($action, fn () => app(ServicePricing::class)->approve($record), __('Цена подтверждена'))),
                Action::make('fixPrice')->label('Исправить')->icon('heroicon-o-pencil-square')
                    ->authorize(Permission::ManagePrices)
                    ->visible(fn (ServicePrice $record) => app(ServicePricing::class)->isEditable($record))
                    ->fillForm(fn (ServicePrice $record) => [
                        'price' => $record->price, 'cost' => $record->cost,
                        'valid_from' => $record->valid_from, 'valid_until' => $record->valid_until,
                    ])
                    ->schema(fn () => $this->priceFields())
                    ->action(function (ServicePrice $record, array $data, Action $action) {
                        RunsDomainActions::run($action, fn () => app(ServicePricing::class)->updatePrice(
                            $record,
                            (string) $data['price'],
                            Carbon::parse($data['valid_from']),
                            filled($data['valid_until'] ?? null) ? Carbon::parse($data['valid_until']) : null,
                            filled($data['cost'] ?? null) ? (string) $data['cost'] : null,
                        ), __('Цена исправлена'));
                    }),
            ])
            ->headerActions([
                Action::make('addPrice')->label('Новая цена')->icon('heroicon-o-plus')
                    ->authorize(Permission::ManagePrices)
                    ->schema(fn () => $this->priceFields())
                    ->action(function (array $data, Action $action) {
                        /** @var Service $service */
                        $service = $this->getOwnerRecord();
                        RunsDomainActions::run($action, fn () => app(ServicePricing::class)->addPrice(
                            $service,
                            (string) $data['price'],
                            Carbon::parse($data['valid_from']),
                            filled($data['valid_until'] ?? null) ? Carbon::parse($data['valid_until']) : null,
                            filled($data['cost'] ?? null) ? (string) $data['cost'] : null,
                        ), __('Цена добавлена'));
                    }),
            ]);
    }

    /** @return list<Component> */
    private function priceFields(): array
    {
        return [
            TextInput::make('price')->label('Цена продажи, ₸')->numeric()->minValue(1)->required(),
            TextInput::make('cost')->label('Себестоимость, ₸')->numeric()->minValue(0)
                ->visible(fn () => auth()->user()?->can(Permission::ViewCosts))
                ->helperText(__('Во что услуга обходится отелю (билеты, продукты, оплата поставщику). Нужна для маржи и маркетинговых расходов.')),
            DatePicker::make('valid_from')->label('Действует с')->required()->default(today()),
            DatePicker::make('valid_until')->label('Действует по')->helperText(__('Пусто — бессрочно. Если новая цена начинается внутри периода действующей, та закончится днём раньше.')),
        ];
    }

    public function isReadOnly(): bool
    {
        return false;
    }
}
