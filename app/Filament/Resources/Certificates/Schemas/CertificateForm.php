<?php

namespace App\Filament\Resources\Certificates\Schemas;

use App\Filament\Resources\Customers\Schemas\CustomerForm;
use App\Filament\Resources\Recipients\Schemas\RecipientForm;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Carbon;

/** Реквизиты черновика. Статус, состав и оплаты меняются действиями на странице сертификата. */
class CertificateForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Покупатель и получатель')->columns(2)->schema([
                Select::make('customer_id')->label('Покупатель')
                    ->relationship('customer', 'full_name')->searchable(['full_name', 'phone', 'email'])->preload()
                    ->getOptionLabelFromRecordUsing(fn ($record) => trim($record->full_name.' '.($record->phone ? "({$record->phone})" : '')))
                    ->createOptionForm(CustomerForm::fields())->required(),
                Select::make('recipient_id')->label('Получатель')
                    ->relationship('recipient', 'full_name')->searchable(['full_name', 'phone', 'email'])->preload()
                    ->getOptionLabelFromRecordUsing(fn ($record) => trim($record->full_name.' '.($record->phone ? "({$record->phone})" : '')))
                    ->createOptionForm(RecipientForm::fields())->required(),
            ]),
            Section::make('Даты')->columns(3)->schema([
                DatePicker::make('sold_at')->label('Дата продажи')->default(today())->required()
                    ->helperText('Цены услуг берутся на эту дату.')
                    ->live(onBlur: true)
                    ->afterStateUpdated(function (Get $get, Set $set, ?string $state, string $operation) {
                        if ($operation === 'create' && $state) {
                            $set('valid_from', $state);
                            $set('valid_until', Carbon::parse($state)->addDays(config('gifter.validity_days'))->toDateString());
                        }
                    }),
                DatePicker::make('valid_from')->label('Действует с')->default(today())->required(),
                DatePicker::make('valid_until')->label('Действует по')->required()->afterOrEqual('valid_from')
                    ->default(fn () => today()->addDays(config('gifter.validity_days'))),
            ]),
            Section::make('Состав')->visibleOn('create')->schema([
                Select::make('package_id')->label('Пакет (необязательно)')
                    ->relationship('package', 'name', fn ($query) => $query->where('is_active', true))->preload()
                    ->helperText('Услуги пакета будут скопированы в сертификат; дальше состав можно править.'),
            ]),
            Textarea::make('notes')->label('Примечание')->columnSpanFull(),
        ]);
    }
}
