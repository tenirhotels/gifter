<?php

namespace App\Filament\Resources\Certificates\Schemas;

use App\Enums\ComplimentaryPurpose;
use App\Enums\Permission;
use App\Filament\Resources\Customers\Schemas\CustomerForm;
use App\Filament\Resources\Recipients\Schemas\RecipientForm;
use App\Filament\Support\ValidityFields;
use App\Models\Customer;
use App\Models\Package;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;

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
            Section::make('Заказчик и получатель')->columns(2)->schema([self::customerField(), self::recipientField()]),
            self::notesField(),
        ]);
    }

    /**
     * @param  bool  $withValidityPreview  в мастере срок показывается в «Сводке», а не рядом с полем
     * @return list<Component>
     */
    public static function productFields(bool $withValidityPreview = true): array
    {
        return [
            Select::make('package_id')->label('Пакет')->required()->live()
                ->relationship('package', 'name', fn ($query) => $query->where('is_active', true)
                    ->when(! auth()->user()?->can(Permission::CreateComplimentary), fn ($q) => $q->where('is_complimentary', false))
                    ->orderBy('is_complimentary')->orderBy('name'))
                ->preload()
                ->getOptionLabelFromRecordUsing(fn (Package $record) => "{$record->name} — {$record->validityLabel()}".($record->is_complimentary ? ' · бесплатный' : ''))
                ->afterStateUpdated(fn ($state, Set $set) => $set('complimentary_purpose', Package::find($state)?->complimentary_purpose?->value))
                ->helperText('Пакет задаёт состав, срок действия и тип: бесплатный пакет даёт бесплатный сертификат.'),
            Placeholder::make('validity_preview')->label('Срок действия')
                ->hidden(fn (Get $get) => ! $withValidityPreview || self::isComplimentary($get))
                ->content(fn (Get $get) => ValidityFields::preview(Package::find($get('package_id')), $get('validity'), $get('sold_at'))),
            DatePicker::make('sold_at')->label('Дата продажи')->default(today())->required()->live()
                ->helperText('Цены и срок считаются от этой даты.')
                ->visible(fn () => auth()->user()?->can(Permission::EditSaleDate)),
        ];
    }

    /** @return list<Component> */
    public static function complimentaryFields(): array
    {
        return [
            Select::make('complimentary_purpose')->label('Цель')->options(ComplimentaryPurpose::class)->required()->live()
                ->default(fn (Get $get) => Package::find($get('package_id'))?->complimentary_purpose)
                ->helperText(fn ($state) => ($state instanceof ComplimentaryPurpose ? $state : ComplimentaryPurpose::tryFrom((string) $state))?->getDescription()),
            Textarea::make('complimentary_reason')->label('Основание (кому и зачем)')->required()
                ->helperText('Выпускается без оплаты после согласования директором; расход — маркетинговый.'),
        ];
    }

    public static function customerField(): Select
    {
        return Select::make('customer_id')->label('Заказчик')->live()
            ->relationship('customer', 'full_name')->searchable(['last_name', 'first_name', 'company', 'bin'])->preload()
            ->getOptionLabelFromRecordUsing(fn (Customer $record) => $record->displayName())
            ->createOptionForm(CustomerForm::fields())
            ->required(fn (Get $get): bool => ! self::isComplimentary($get) || self::hasPaidExtras($get))
            ->helperText(fn (Get $get): ?string => self::isComplimentary($get)
                ? (self::hasPaidExtras($get) ? 'Есть платные услуги — заказчик, который их оплатит, обязателен.' : 'Для бесплатного — необязательно (партнёр, блогер, компания).')
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

    /** Есть ли в бесплатном сертификате платные доп. услуги (тумблер в мастере). */
    public static function hasPaidExtras(Get $get): bool
    {
        return collect($get('extras') ?? [])->contains(fn ($extra) => ! empty($extra['paid']) && ! empty($extra['service_id']));
    }

    /** Бесплатный ли сертификат — по выбранному пакету. */
    public static function isComplimentary(Get $get): bool
    {
        return (bool) Package::find($get('package_id'))?->is_complimentary;
    }
}
