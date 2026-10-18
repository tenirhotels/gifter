<?php

namespace App\Filament\Resources\Certificates\Pages;

use App\Enums\PaymentMethod;
use App\Exceptions\DomainRuleViolation;
use App\Filament\Resources\Certificates\CertificateResource;
use App\Filament\Resources\Certificates\Schemas\CertificateForm;
use App\Filament\Support\ValidityFields;
use App\Models\Package;
use App\Models\Service;
use App\Services\CertificateManager;
use App\Services\PaymentManager;
use App\Support\Money;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Resources\Pages\CreateRecord\Concerns\HasWizard;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Wizard\Step;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\HtmlString;

/**
 * Мастер продажи: пакет → покупатель и получатель → доп. услуги и итог → счёт.
 * Всё создаётся одной транзакцией: при ошибке не остаётся «полу-черновика».
 */
class CreateCertificate extends CreateRecord
{
    use HasWizard;

    protected static string $resource = CertificateResource::class;

    protected static ?string $title = 'Продажа сертификата';

    public function getSteps(): array
    {
        return [
            Step::make('Продукт')->icon('heroicon-o-squares-2x2')->schema([
                Grid::make(3)->schema(CertificateForm::productFields()),
                Grid::make(2)->visible(fn (Get $get) => CertificateForm::isComplimentary($get))->schema([
                    ...CertificateForm::complimentaryFields(),
                    ValidityFields::make(),
                ]),
            ]),
            Step::make('Покупатель и получатель')->icon('heroicon-o-user')->columns(2)
                ->schema([CertificateForm::customerField(), CertificateForm::recipientField()]),
            Step::make('Состав и стоимость')->icon('heroicon-o-list-bullet')->schema([
                Repeater::make('extras')->label('Доп. услуги к пакету')->addActionLabel('Добавить доп. услугу')
                    ->defaultItems(0)->live()->columns(2)->reorderable(false)
                    ->schema([
                        Select::make('service_id')->label('Услуга')->required()->searchable()->live()
                            ->options(fn () => Service::where('is_active', true)->orderBy('name')->pluck('name', 'id')),
                        TextInput::make('quantity')->label('Количество')->integer()->minValue(1)->default(1)->required()->live(onBlur: true),
                    ]),
                Placeholder::make('quote')->label('Расчёт по прайсу')->content(fn (Get $get) => $this->quoteTable($get)),
                CertificateForm::notesField(),
            ]),
            Step::make('Счёт')->icon('heroicon-o-banknotes')
                ->visible(fn (Get $get) => ! CertificateForm::isComplimentary($get))
                ->schema([
                    Toggle::make('create_invoice')->label('Сразу выставить счёт на всю сумму')->default(true)->live(),
                    Grid::make(2)->visible(fn (Get $get) => (bool) $get('create_invoice'))->schema([
                        Select::make('payment_method')->label('Способ')->options(PaymentMethod::class)->default(PaymentMethod::KaspiInvoice->value)->required(),
                        TextInput::make('invoice_number')->label('№ счёта в банке')->maxLength(255),
                    ]),
                    Placeholder::make('invoice_hint')->hiddenLabel()
                        ->content('Оплату отметите на странице сертификата, когда деньги поступят; перечисление на р/с подтверждают Финансы. После оплаты — «Выпустить».'),
                ]),
        ];
    }

    private function quoteTable(Get $get): HtmlString|string
    {
        $package = Package::find($get('package_id'));
        if (! $package) {
            return 'Выберите пакет на первом шаге.';
        }
        $quote = app(CertificateManager::class)->quote($package, array_values($get('extras') ?? []), Carbon::parse($get('sold_at') ?: today()));
        $rows = collect($quote['lines'])->map(fn (array $line) => '<tr><td>'.e($line['name']).($line['from_package'] ? '' : ' <span style="opacity:.6">(доп.)</span>')
            .'</td><td style="text-align:right">'.$line['quantity'].'</td><td style="text-align:right">'.Money::format($line['unit_price'])
            .'</td><td style="text-align:right">'.Money::format($line['line_total']).'</td></tr>')->implode('');
        $missing = $quote['missing'] ? '<p style="color:#dc2626">Нет цены на дату продажи: '.e(implode(', ', $quote['missing'])).'</p>' : '';

        return new HtmlString('<table style="width:100%;font-size:.875rem"><thead><tr style="text-align:left;opacity:.7"><th>Услуга</th><th style="text-align:right">Кол-во</th>'
            .'<th style="text-align:right">Цена</th><th style="text-align:right">Сумма</th></tr></thead><tbody>'.$rows
            .'</tbody><tfoot><tr style="font-weight:600"><td colspan="3">Итого</td><td style="text-align:right">'.Money::format($quote['total']).'</td></tr></tfoot></table>'.$missing);
    }

    protected function handleRecordCreation(array $data): Model
    {
        try {
            return DB::transaction(function () use ($data) {
                $certificates = app(CertificateManager::class);
                $certificate = $certificates->createDraft($data);
                foreach ($data['extras'] ?? [] as $extra) {
                    $certificates->addItem($certificate, Service::findOrFail($extra['service_id']), (int) $extra['quantity']);
                }
                if (! $certificate->isComplimentary() && ($data['create_invoice'] ?? false)) {
                    $method = $data['payment_method'] instanceof PaymentMethod ? $data['payment_method'] : PaymentMethod::from($data['payment_method']);
                    app(PaymentManager::class)->invoice($certificate->refresh(), $method, invoiceNumber: $data['invoice_number'] ?? null);
                }

                return $certificate->refresh();
            });
        } catch (DomainRuleViolation $e) {
            Notification::make()->danger()->title($e->getMessage())->send();
            $this->halt();
        }
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Сертификат оформлен';
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}
