<?php

namespace App\Filament\Resources\Services\RelationManagers;

use App\Filament\Support\RunsDomainActions;
use App\Models\Service;
use App\Services\ServicePricing;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
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
                TextColumn::make('valid_until')->label('По')->date('d.m.Y')->placeholder('бессрочно'),
                TextColumn::make('price')->label('Цена')->formatStateUsing(fn ($state) => Money::format($state)),
                TextColumn::make('creator.name')->label('Добавил')->placeholder('—'),
                TextColumn::make('created_at')->label('Добавлено')->dateTime('d.m.Y H:i'),
            ])
            ->defaultSort('valid_from', 'desc')
            ->headerActions([
                Action::make('addPrice')->label('Новая цена')->icon('heroicon-o-plus')
                    ->schema([
                        TextInput::make('price')->label('Цена, ₸')->numeric()->minValue(1)->required(),
                        DatePicker::make('valid_from')->label('Действует с')->required()->default(today()),
                        DatePicker::make('valid_until')->label('Действует по')->helperText('Пусто — бессрочно. Открытая предыдущая цена закроется автоматически.'),
                    ])
                    ->action(function (array $data, Action $action) {
                        /** @var Service $service */
                        $service = $this->getOwnerRecord();
                        RunsDomainActions::run($action, fn () => app(ServicePricing::class)->addPrice(
                            $service,
                            (string) $data['price'],
                            Carbon::parse($data['valid_from']),
                            filled($data['valid_until'] ?? null) ? Carbon::parse($data['valid_until']) : null,
                        ), 'Цена добавлена');
                    }),
            ]);
    }

    public function isReadOnly(): bool
    {
        return false;
    }
}
