<?php

namespace App\Filament\Resources\Certificates\Schemas;

use App\Enums\CertificateKind;
use App\Enums\Permission;
use App\Filament\Resources\Customers\Schemas\CustomerForm;
use App\Filament\Resources\Recipients\Schemas\RecipientForm;
use App\Models\Customer;
use App\Models\Package;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\ToggleButtons;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Support\Carbon;

/**
 * Реквизиты черновика. Продукт — пакет: он задаёт базовый состав и срок действия.
 * Блоки полей общие для мастера продажи (CreateCertificate) и правки черновика.
 */
class CertificateForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Продукт')->columns(3)->schema(self::productFields()),
            Section::make('Тип')->columns(2)->visible(fn () => auth()->user()?->can(Permission::CreateComplimentary))->schema(self::kindFields()),
            Section::make('Покупатель и получатель')->columns(2)->schema([self::customerField(), self::recipientField()]),
            self::notesField(),
        ]);
    }

    /** @return list<Component> */
    public static function productFields(): array
    {
        return [
            Select::make('package_id')->label('Пакет')->required()->live()
                ->relationship('package', 'name', fn ($query) => $query->where('is_active', true))->preload()
                ->getOptionLabelFromRecordUsing(fn (Package $record) => "{$record->name} — {$record->validityLabel()}")
                ->helperText('Пакет задаёт базовый состав и срок действия.'),
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
    public static function kindFields(): array
    {
        $complimentary = fn (Get $get): bool => self::isComplimentary($get('kind'));

        return [
            ToggleButtons::make('kind')->label('Тип сертификата')->options(CertificateKind::class)->inline()
                ->default(CertificateKind::Sale)->required()->live(),
            Textarea::make('complimentary_reason')->label('Основание (маркетинговая цель)')
                ->helperText('Бесплатный выпускается без оплаты после согласования директором; расход — маркетинговый.')
                ->visible($complimentary)->required($complimentary),
        ];
    }

    public static function customerField(): Select
    {
        return Select::make('customer_id')->label('Покупатель')
            ->relationship('customer', 'full_name')->searchable(['last_name', 'first_name', 'company', 'bin'])->preload()
            ->getOptionLabelFromRecordUsing(fn (Customer $record) => $record->displayName())
            ->createOptionForm(CustomerForm::fields())
            ->required(fn (Get $get): bool => ! self::isComplimentary($get('kind')))
            ->helperText(fn (Get $get): ?string => self::isComplimentary($get('kind')) ? 'Для бесплатного — необязательно.' : 'Найдите по фамилии, компании или БИН, или создайте нового (+).');
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

    public static function isComplimentary(mixed $kind): bool
    {
        return ($kind instanceof CertificateKind ? $kind : CertificateKind::tryFrom((string) $kind)) === CertificateKind::Complimentary;
    }
}
