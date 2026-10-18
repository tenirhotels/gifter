<?php

namespace App\Filament\Resources\Certificates\Schemas;

use App\Enums\ComplimentaryPurpose;
use App\Enums\Permission;
use App\Filament\Resources\Customers\Schemas\CustomerForm;
use App\Filament\Resources\Recipients\Schemas\RecipientForm;
use App\Models\Customer;
use App\Models\Package;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Support\Carbon;

/**
 * Реквизиты черновика. Продукт — пакет: он задаёт состав, срок действия и тип
 * (бесплатный пакет даёт бесплатный сертификат).
 * Блоки полей общие для мастера продажи (CreateCertificate) и правки черновика.
 */
class CertificateForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Продукт')->columns(3)->schema(self::productFields()),
            Section::make('Бесплатный сертификат')->columns(2)->visible(fn (Get $get) => self::isComplimentary($get))->schema(self::complimentaryFields()),
            Section::make('Покупатель и получатель')->columns(2)->schema([self::customerField(), self::recipientField()]),
            self::notesField(),
        ]);
    }

    /** @return list<Component> */
    public static function productFields(): array
    {
        return [
            Select::make('package_id')->label('Пакет')->required()->live()
                ->relationship('package', 'name', fn ($query) => $query->where('is_active', true)
                    ->when(! auth()->user()?->can(Permission::CreateComplimentary), fn ($q) => $q->where('is_complimentary', false))
                    ->orderBy('is_complimentary')->orderBy('name'))
                ->preload()
                ->getOptionLabelFromRecordUsing(fn (Package $record) => "{$record->name} — {$record->validityLabel()}".($record->is_complimentary ? ' · бесплатный' : ''))
                ->helperText('Пакет задаёт состав, срок действия и тип: бесплатный пакет даёт бесплатный сертификат.'),
            Placeholder::make('validity_preview')->label('Срок действия')
                ->content(function (Get $get) {
                    $package = Package::find($get('package_id'));
                    if (! $package) {
                        return 'по выбранному пакету';
                    }
                    $soldAt = Carbon::parse($get('sold_at') ?: today());

                    return $soldAt->format('d.m.Y').' — '.$package->validUntil($soldAt)->format('d.m.Y');
                }),
            DatePicker::make('sold_at')->label('Дата продажи')->default(today())->required()->live()
                ->helperText('Цены и срок считаются от этой даты.')
                ->visible(fn () => auth()->user()?->can(Permission::EditSaleDate)),
        ];
    }

    /** @return list<Component> */
    public static function complimentaryFields(): array
    {
        return [
            Select::make('complimentary_purpose')->label('Цель')->options(ComplimentaryPurpose::class)->required()
                ->default(fn (Get $get) => Package::find($get('package_id'))?->complimentary_purpose),
            Textarea::make('complimentary_reason')->label('Основание (кому и зачем)')->required()
                ->helperText('Выпускается без оплаты после согласования директором; расход — маркетинговый.'),
        ];
    }

    public static function customerField(): Select
    {
        return Select::make('customer_id')->label('Покупатель')
            ->relationship('customer', 'full_name')->searchable(['last_name', 'first_name', 'company', 'bin'])->preload()
            ->getOptionLabelFromRecordUsing(fn (Customer $record) => $record->displayName())
            ->createOptionForm(CustomerForm::fields())
            ->required(fn (Get $get): bool => ! self::isComplimentary($get))
            ->helperText(fn (Get $get): ?string => self::isComplimentary($get)
                ? 'Для бесплатного — необязательно (например, партнёр по бартеру).'
                : 'Найдите по фамилии, компании или БИН, или создайте нового (+).');
    }

    public static function recipientField(): Select
    {
        return Select::make('recipient_id')->label('Получатель')
            ->relationship('recipient', 'full_name')->searchable(['last_name', 'first_name'])->preload()
            ->createOptionForm(RecipientForm::fields())
            ->helperText('Можно не указывать, если получатель пока неизвестен, и добавить позже.');
    }

    public static function notesField(): Textarea
    {
        return Textarea::make('notes')->label('Примечание')->columnSpanFull();
    }

    /** Бесплатный ли сертификат — по выбранному пакету. */
    public static function isComplimentary(Get $get): bool
    {
        return (bool) Package::find($get('package_id'))?->is_complimentary;
    }
}
