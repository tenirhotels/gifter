<?php

namespace App\Filament\Resources\Certificates\RelationManagers;

use App\Enums\Permission;
use App\Filament\Resources\Certificates\CertificateResource;
use App\Filament\Support\RunsDomainActions;
use App\Models\Certificate;
use App\Models\CertificateItem;
use App\Models\Package;
use App\Models\Service;
use App\Services\CertificateManager;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** Состав сертификата. Правится только в черновике без открытых счетов. */
class ItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'items';

    protected static ?string $title = 'Состав';

    public function table(Table $table): Table
    {
        $editable = fn (): bool => $this->certificate()->isDraft() && ! $this->certificate()->isComplimentaryApproved();

        return $table
            ->description('Сертификат погашается только целиком: услуги по отдельности не гасятся и не обмениваются на деньги.')
            ->columns([
                TextColumn::make('service_name_snapshot')->label('Услуга'),
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
                TextColumn::make('priceVersion.valid_from')->label('Тариф с')->date('d.m.Y')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->paginated(false)
            ->headerActions([
                Action::make('addItem')->label('Добавить услугу')->icon('heroicon-o-plus')->visible($editable)->authorize(Permission::ComposeDrafts)
                    ->schema([
                        Select::make('service_id')->label('Услуга')->required()->searchable()->preload()
                            ->options(fn () => Service::where('is_active', true)->orderBy('name')->pluck('name', 'id')),
                        TextInput::make('quantity')->label('Количество')->integer()->minValue(1)->default(1)->required(),
                    ])
                    ->action(function (array $data, Action $action) {
                        RunsDomainActions::run($action, fn () => app(CertificateManager::class)
                            ->addItem($this->certificate(), Service::findOrFail($data['service_id']), (int) $data['quantity']), 'Услуга добавлена');
                        $this->refreshPage();
                    }),
                Action::make('applyPackage')->label('Добавить пакет')->icon('heroicon-o-squares-plus')->color('gray')->visible($editable)->authorize(Permission::ComposeDrafts)
                    ->schema([
                        Select::make('package_id')->label('Пакет')->required()
                            ->options(fn () => Package::where('is_active', true)->orderBy('name')->pluck('name', 'id')),
                    ])
                    ->action(function (array $data, Action $action) {
                        RunsDomainActions::run($action, fn () => app(CertificateManager::class)
                            ->applyPackage($this->certificate(), Package::findOrFail($data['package_id'])), 'Услуги пакета добавлены');
                        $this->refreshPage();
                    }),
            ])
            ->recordActions([
                Action::make('remove')->label('Убрать')->icon('heroicon-o-x-mark')->color('danger')->visible($editable)->authorize(Permission::ComposeDrafts)
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
