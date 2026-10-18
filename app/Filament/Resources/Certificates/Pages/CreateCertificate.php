<?php

namespace App\Filament\Resources\Certificates\Pages;

use App\Enums\PaymentMethod;
use App\Exceptions\DomainRuleViolation;
use App\Filament\Resources\CertificateBatches\CertificateBatchResource;
use App\Filament\Resources\Certificates\CertificateResource;
use App\Filament\Resources\Certificates\Schemas\CertificateForm;
use App\Filament\Support\ValidityFields;
use App\Models\CertificateBatch;
use App\Models\Customer;
use App\Models\Package;
use App\Models\Service;
use App\Services\CertificateBatchManager;
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
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\HtmlString;

/**
 * Мастер продажи: пакет и количество → заказчик и получатель → доп. услуги и итог → счёт.
 * Количество > 1 — группа сертификатов (CertificateBatchManager): общий состав, один счёт, выпуск вместе.
 * Всё создаётся одной транзакцией: при ошибке не остаётся «полу-черновика».
 */
class CreateCertificate extends CreateRecord
{
    use HasWizard;

    protected static string $resource = CertificateResource::class;

    protected static ?string $title = 'Оформление сертификата';

    /** Шаги мастера слева, «Сводка» справа: что оформляется, на какой срок и на какую сумму — видно на любом шаге. */
    public function form(Schema $schema): Schema
    {
        return parent::form($schema)->components([
            Grid::make(['default' => 1, 'lg' => 3])->columnSpanFull()->schema([
                $this->getWizardComponent()->columnSpan(['default' => 1, 'lg' => 2]),
                Section::make('Сводка')->icon('heroicon-o-clipboard-document-list')->columnSpan(1)
                    ->extraAttributes(['class' => 'gf-summary'])
                    ->schema([Text::make(fn (Get $get) => $this->summary($get))]),
            ]),
        ]);
    }

    public function getSteps(): array
    {
        [$package, , $soldAt] = CertificateForm::productFields(withValidityPreview: false);

        return [
            Step::make('Продукт')->icon('heroicon-o-squares-2x2')->schema([
                Grid::make(['default' => 1, 'md' => 2])->schema([
                    $package->columnSpanFull(),
                    TextInput::make('certificate_count')->label('Количество сертификатов')->integer()
                        ->minValue(1)->maxValue(CertificateBatchManager::MAX_QUANTITY)->default(1)->required()->live(onBlur: true)
                        ->helperText('Больше 1 — группа: общий счёт для платных, получатель у каждого свой.'),
                    $soldAt,
                ]),
                Grid::make(2)->visible(fn (Get $get) => CertificateForm::isComplimentary($get))->schema([
                    ...CertificateForm::complimentaryFields(),
                    ValidityFields::make(),
                ]),
            ]),
            Step::make('Заказчик')->icon('heroicon-o-user')->columns(2)
                ->schema([
                    CertificateForm::customerField(),
                    CertificateForm::recipientField()->hidden(fn (Get $get) => self::count($get) > 1),
                    Placeholder::make('recipients_hint')->label('Получатели')->visible(fn (Get $get) => self::count($get) > 1)
                        ->content('У каждого сертификата свой получатель — укажите их после оформления.'),
                ]),
            Step::make('Состав')->icon('heroicon-o-list-bullet')->schema([
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
                    Toggle::make('create_invoice')->live()->default(true)
                        ->label(fn (Get $get) => self::count($get) > 1 ? 'Сразу выставить один счёт на всю группу' : 'Сразу выставить счёт на всю сумму'),
                    Grid::make(2)->visible(fn (Get $get) => (bool) $get('create_invoice'))->schema([
                        Select::make('payment_method')->label('Способ')->options(PaymentMethod::class)->default(PaymentMethod::KaspiInvoice->value)->required(),
                        TextInput::make('invoice_number')->label('№ счёта в банке')->maxLength(255),
                    ]),
                    Placeholder::make('invoice_hint')->hiddenLabel()
                        ->content('Оплату отметите на странице сертификата (или группы), когда деньги поступят; перечисление на р/с подтверждают Финансы. После оплаты — «Выпустить».'),
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

        $count = self::count($get);
        $total = $count > 1
            ? '<tr><td colspan="3">За 1 сертификат</td><td style="text-align:right">'.Money::format($quote['total']).'</td></tr>'
                .'<tr style="font-weight:600"><td colspan="3">Итого за '.$count.' шт.</td><td style="text-align:right">'.Money::format(Money::multiply($quote['total'], $count)).'</td></tr>'
            : '<tr style="font-weight:600"><td colspan="3">Итого</td><td style="text-align:right">'.Money::format($quote['total']).'</td></tr>';

        return new HtmlString('<table style="width:100%;font-size:.875rem"><thead><tr style="text-align:left;opacity:.7"><th>Услуга</th><th style="text-align:right">Кол-во</th>'
            .'<th style="text-align:right">Цена</th><th style="text-align:right">Сумма</th></tr></thead><tbody>'.$rows
            .'</tbody><tfoot>'.$total.'</tfoot></table>'.$missing);
    }

    /** Сводка справа: ведёт итоговая сумма, ниже — пакет, срок, состав, заказчик. */
    private function summary(Get $get): HtmlString
    {
        $package = Package::find($get('package_id'));
        if (! $package) {
            return new HtmlString('<div class="gf-summary-empty">Выберите пакет: здесь появятся состав, срок действия и сумма.</div>');
        }
        $count = self::count($get);
        $free = (bool) $package->is_complimentary;
        $quote = app(CertificateManager::class)->quote($package, array_values($get('extras') ?? []), Carbon::parse($get('sold_at') ?: today()));
        $total = Money::multiply($quote['total'], $count);
        $validity = $free
            ? ValidityFields::preview($package, $get('validity'), $get('sold_at'))
            : ValidityFields::preview($package, null, $get('sold_at'));
        $customer = Customer::find($get('customer_id'))?->displayName();
        $extras = collect($quote['lines'])->reject(fn (array $line) => $line['from_package']);

        $row = fn (string $label, string $value) => '<div class="gf-summary-row"><dt>'.e($label).'</dt><dd>'.$value.'</dd></div>';
        $rows = $row('Пакет', e($package->name))
            .$row('Тип', $free ? 'бесплатный, после согласования директора' : 'продажа')
            .$row('Срок', e($validity))
            .$row('Состав', e(collect($quote['lines'])->where('from_package', true)->map(fn (array $l) => "{$l['name']} × {$l['quantity']}")->implode(', ') ?: 'пакет без услуг'))
            .($extras->isNotEmpty() ? $row('Доп. услуги', e($extras->map(fn (array $l) => "{$l['name']} × {$l['quantity']}")->implode(', '))) : '')
            .$row('Заказчик', $customer ? e($customer) : '<span class="gf-muted">'.($free ? 'не обязателен' : 'не выбран').'</span>')
            .($count > 1 ? $row('Сертификатов', $count.' шт., группа · <span class="gf-nowrap">'.Money::format($quote['total']).'</span> за 1') : '');
        $missing = $quote['missing'] ? '<p class="gf-summary-warn">Нет цены на дату продажи: '.e(implode(', ', $quote['missing'])).'</p>' : '';

        return new HtmlString('<div class="gf-summary-total"><span>'.($free ? 'Стоимость по прайсу' : 'К оплате').'</span><strong>'.Money::format($total).'</strong></div>'
            .'<dl class="gf-summary-list">'.$rows.'</dl>'.$missing);
    }

    private static function count(Get $get): int
    {
        return max(1, (int) $get('certificate_count'));
    }

    protected function handleRecordCreation(array $data): Model
    {
        try {
            return DB::transaction(function () use ($data) {
                if ((int) ($data['certificate_count'] ?? 1) > 1) {
                    return $this->createPurchase($data);
                }
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

    /** Несколько сертификатов — группа (платная или бесплатная): общие состав, счёт и выпуск. */
    private function createPurchase(array $data): Model
    {
        $batches = app(CertificateBatchManager::class);
        $batch = $batches->create(Package::findOrFail($data['package_id']), (int) $data['certificate_count'], $data);
        if (! $batch->isComplimentary() && ($data['create_invoice'] ?? false)) {
            $method = $data['payment_method'] instanceof PaymentMethod ? $data['payment_method'] : PaymentMethod::from($data['payment_method']);
            $batches->invoice($batch, $method, $data['invoice_number'] ?? null);
        }
        $this->purchase = $batch;

        return $batch->certificates()->firstOrFail();
    }

    public ?CertificateBatch $purchase = null;

    protected function getCreatedNotificationTitle(): ?string
    {
        return $this->purchase ? "Оформлено: {$this->purchase->shortLabel()}, {$this->purchase->quantity} сертификатов" : 'Сертификат оформлен';
    }

    protected function getRedirectUrl(): string
    {
        return $this->purchase
            ? CertificateBatchResource::getUrl('view', ['record' => $this->purchase])
            : $this->getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}
