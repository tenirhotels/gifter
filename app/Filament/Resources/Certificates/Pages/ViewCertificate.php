<?php

namespace App\Filament\Resources\Certificates\Pages;

use App\Enums\CertificateKind;
use App\Enums\CertificateStatus;
use App\Enums\FulfillmentStatus;
use App\Enums\NameMatch;
use App\Enums\PaymentMethod;
use App\Enums\Permission;
use App\Enums\ReservationReview;
use App\Exceptions\DomainRuleViolation;
use App\Filament\Resources\CertificateBatches\CertificateBatchResource;
use App\Filament\Resources\Certificates\CertificateResource;
use App\Filament\Resources\Recipients\Schemas\RecipientForm;
use App\Filament\Support\ContactFields;
use App\Filament\Support\RunsDomainActions;
use App\Filament\Support\SellerCommissionForm;
use App\Filament\Support\WithoutPageHeading;
use App\Models\Certificate;
use App\Models\Recipient;
use App\Models\User;
use App\Services\AdminCorrections;
use App\Services\ArrivalSync;
use App\Services\CertificateDocument;
use App\Services\CertificateMailer;
use App\Services\CertificateManager;
use App\Services\EpayPayments;
use App\Services\KaspiPayments;
use App\Services\PaymentManager;
use App\Services\Pms\PmsClient;
use App\Services\Pms\PmsException;
use App\Services\RefundManager;
use App\Services\ReservationExceptionManager;
use App\Services\ReservationManager;
use App\Support\Money;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Support\Carbon;
use Illuminate\Support\HtmlString;

class ViewCertificate extends ViewRecord
{
    use WithoutPageHeading;

    protected static string $resource = CertificateResource::class;

    public function getTitle(): string
    {
        return __('Сертификат :p1', ['p1' => $this->getRecord()->number]);
    }

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make()->label('Реквизиты')->visible(fn (Certificate $record) => $record->isDraft()),

            // У черновика из группы счёт, оплата, согласование, состав и выпуск — на её странице.
            Action::make('openBatch')->icon('heroicon-o-rectangle-stack')
                ->label(fn (Certificate $record) => $record->batch->shortLabel().($record->isDraft() ? ($record->batch->isComplimentary() ? __(': согласование, выпуск') : __(': счёт, оплата, выпуск')) : ''))
                ->color(fn (Certificate $record) => $record->isDraft() ? 'primary' : 'gray')
                ->visible(fn (Certificate $record) => $record->batch_id && auth()->user()?->can('view', $record->batch))
                ->url(fn (Certificate $record) => CertificateBatchResource::getUrl('view', ['record' => $record->batch_id])),

            // Истёк без брони: бронь на свободную дату — заявка Front Office, согласует директор по продажам.
            Action::make('requestException')->label('Бронь по исключению')->icon('heroicon-o-calendar-days')->color('warning')
                ->authorize(Permission::RequestReservationException)
                ->visible(fn (Certificate $record) => $record->status === CertificateStatus::Expired && ! $record->hasPendingException())
                ->modalDescription(__('Сертификат истёк без брони. Выберите свободные даты и внесите бронь из PMS: заявку согласует директор по продажам, после чего сертификат вернётся в работу.'))
                ->modalSubmitActionLabel(__('Отправить директору'))
                ->fillForm(fn (Certificate $record) => ['guest_name' => trim($record->recipient?->last_name.' '.$record->recipient?->first_name)])
                ->schema([
                    TextInput::make('booking_number')->label('Номер брони PMS')->required()->maxLength(64)->live(onBlur: true),
                    Placeholder::make('pms_preview')->hiddenLabel()->visible(fn () => PmsClient::make()->isActive())
                        ->content(fn (Certificate $record, Get $get) => self::pmsPreview($record, (string) $get('booking_number'))),
                    TextInput::make('guest_name')->label('Гость, как в брони')->required(fn () => ! PmsClient::make()->isActive())->maxLength(255)
                        ->visible(fn () => ! PmsClient::make()->isActive()),
                    Grid::make(2)->visible(fn () => ! PmsClient::make()->isActive())->schema([
                        DatePicker::make('arrival')->label('Заезд')->required(fn () => ! PmsClient::make()->isActive())->minDate(today()),
                        DatePicker::make('departure')->label('Выезд')->required(fn () => ! PmsClient::make()->isActive())->after('arrival'),
                    ]),
                    Textarea::make('reason')->label('Причина исключения')->required()->helperText(__('Что произошло и почему бронь не была поставлена вовремя.')),
                ])
                ->action(fn (Certificate $record, array $data, Action $action) => $this->run($action,
                    fn () => app(ReservationExceptionManager::class)->request($record, $data), __('Заявка отправлена директору'))),
            Action::make('approveException')->label('Согласовать исключение')->icon('heroicon-o-hand-thumb-up')->color('success')
                ->authorize(Permission::ApproveReservationException)
                ->visible(fn (Certificate $record) => $record->status === CertificateStatus::Expired && $record->hasPendingException())
                ->modalDescription(function (Certificate $record) {
                    $e = $record->reservationExceptions()->where('status', 'pending')->latest('id')->first();

                    return $e ? __('Бронь № :p1, :p2–:p3, гость :p4. Причина: :p5', ['p1' => $e->booking_number, 'p2' => $e->arrival_date->format('d.m'), 'p3' => $e->departure_date->format('d.m.Y'), 'p4' => $e->guest_name, 'p5' => $e->reason]) : null;
                })
                ->schema([Textarea::make('comment')->label('Комментарий')])
                ->action(fn (Certificate $record, array $data, Action $action) => $this->run($action,
                    fn () => app(ReservationExceptionManager::class)->approve($record->reservationExceptions()->where('status', 'pending')->latest('id')->firstOrFail(), $data['comment'] ?? null),
                    __('Исключение согласовано, сертификат возвращён в работу'))),
            Action::make('rejectException')->label('Отклонить исключение')->icon('heroicon-o-hand-thumb-down')->color('danger')
                ->authorize(Permission::ApproveReservationException)
                ->visible(fn (Certificate $record) => $record->status === CertificateStatus::Expired && $record->hasPendingException())
                ->schema([Textarea::make('comment')->label('Причина отказа')->required()])
                ->action(fn (Certificate $record, array $data, Action $action) => $this->run($action,
                    fn () => app(ReservationExceptionManager::class)->reject($record->reservationExceptions()->where('status', 'pending')->latest('id')->firstOrFail(), $data['comment']), __('Заявка отклонена'))),

            Action::make('setSeller')->label('Продавец')->icon('heroicon-o-user-circle')->color('gray')
                ->authorize(Permission::AssignSeller)
                ->visible(fn (Certificate $record) => $record->isDraft() && ! $record->isComplimentary() && ! $record->batch_id)
                ->modalDescription(__('Комиссию после использования сертификата получит выбранный продавец. После выпуска сменить продавца нельзя.'))
                ->fillForm(fn (Certificate $record) => ['seller' => $record->sold_by])
                ->schema([Select::make('seller')->label('Продавец')->required()->searchable()->options(fn () => User::sellerOptions()),
                    Textarea::make('reason')->label('Причина смены продавца')->required()->minLength(5)->helperText(__('Причину увидит директор по продажам.'))])
                ->action(fn (Certificate $record, array $data, Action $action) => $this->run($action,
                    fn () => $this->manager()->setSeller($record, (int) $data['seller'], reason: $data['reason'] ?? null), __('Продавец назначен'))),

            Action::make('sellerCommission')->label('Продавец и комиссия')->icon('heroicon-o-banknotes')->color('gray')
                ->visible(fn (Certificate $record) => SellerCommissionForm::allowed() && ! $record->isDraft() && $record->kind === CertificateKind::Sale)
                ->modalDescription(__('Для выпущенных сертификатов, в том числе перенесённых из таблицы: размер комиссии задаётся здесь. Использованный сертификат — комиссия «Начислена» (дальше согласование и выплата). Согласованную или выплаченную комиссию изменить нельзя.'))
                ->fillForm(fn (Certificate $record) => ['seller' => $record->sold_by])
                ->schema(SellerCommissionForm::schema())
                ->action(function (Certificate $record, array $data) {
                    if (SellerCommissionForm::apply($record, $data)) {
                        $this->redirect(CertificateResource::getUrl('view', ['record' => $record]));
                    }
                }),

            Action::make('approveComplimentary')->label('Согласовать бесплатный')->icon('heroicon-o-hand-thumb-up')->color('success')
                ->authorize(Permission::ApproveComplimentary)
                ->visible(fn (Certificate $record) => $record->isDraft() && $record->isComplimentary() && ! $record->isComplimentaryApproved() && ! $record->batch_id)
                ->modalDescription(fn (Certificate $record) => __('Срок: :p1 — :p2', ['p1' => $record->valid_from->format('d.m.Y'), 'p2' => $record->valid_until->format('d.m.Y')])
                    .($record->custom_validity ? __(' (задан вручную)') : '').__('. Маркетинговый расход по себестоимости: :p1', ['p1' => Money::format($record->marketing_cost)])
                    .($record->hasUnknownCost() ? __(' (у части услуг себестоимость не указана)') : '')
                    .($record->hasPayablePart() ? __('. Платные услуги оплачивает заказчик: :p1 (выпуск — после оплаты)', ['p1' => Money::format($record->payable_amount)]) : '')
                    .__('. После согласования состав фиксируется.'))
                ->schema([Textarea::make('comment')->label('Комментарий')])
                ->action(fn (Certificate $record, array $data, Action $action) => $this->run($action,
                    fn () => $this->manager()->approveComplimentary($record, $data['comment'] ?? null), __('Бесплатный сертификат согласован'))),

            // Счёт и оплата одиночной продажи — сразу в шапке (частичные счета, сверка — во вкладке «Оплата»).
            Action::make('invoice')->label('Выставить счёт')->icon('heroicon-o-document-text')
                ->authorize(Permission::ManagePayments)
                ->visible(fn (Certificate $record) => $this->isOwnSaleDraft($record) && ! $record->pendingPayment()
                    && Money::toMinor($record->invoicedAmount()) < Money::toMinor($record->payable_amount))
                ->fillForm(fn (Certificate $record) => [
                    'method' => PaymentMethod::KaspiInvoice->value,
                    'amount' => Money::fromMinor(Money::toMinor($record->payable_amount) - Money::toMinor($record->invoicedAmount())),
                ])
                ->schema([
                    Select::make('method')->label('Способ')->options(PaymentMethod::manualOptions())->required(),
                    TextInput::make('amount')->label('Сумма, ₸')->numeric()->minValue(1)->required()->helperText(__('По умолчанию — весь неоплаченный остаток.')),
                    TextInput::make('invoice_number')->label('Номер счёта в банке')->maxLength(255),
                ])
                ->action(fn (Certificate $record, array $data, Action $action) => $this->run($action, fn () => app(PaymentManager::class)->invoice(
                    $record, $data['method'] instanceof PaymentMethod ? $data['method'] : PaymentMethod::from($data['method']),
                    (string) $data['amount'], $data['invoice_number'] ?? null), __('Счёт выставлен'))),

            Action::make('markPaid')->label('Оплачен')->icon('heroicon-o-check')->color('success')
                ->visible(fn (Certificate $record) => $this->isOwnSaleDraft($record) && $record->pendingPayment() !== null && ! $record->pendingPayment()->method->isGateway())
                ->authorize(fn (Certificate $record) => auth()->user()->can($record->pendingPayment()?->method->requiresFinanceConfirmation()
                    ? Permission::ReceiveBankTransfers : Permission::ReceivePayments))
                ->modalDescription(fn (Certificate $record) => ($line = $record->pendingPayment())
                    ? $line->method->getLabel().($line->invoice_number ? ' № '.$line->invoice_number : '').' · '.Money::format($line->amount) : null)
                ->schema([DateTimePicker::make('paid_at')->label('Дата поступления')->default(now())->maxDate(now())->required()])
                ->action(fn (Certificate $record, array $data, Action $action) => $this->run($action,
                    fn () => app(PaymentManager::class)->markPaid($record->pendingPayment(), Carbon::parse($data['paid_at'])), __('Оплата отмечена'))),

            // Оплата картой по ссылке банка: платёж подтверждает банк (API), вручную его не отмечают.
            Action::make('epayLink')->label('Ссылка на оплату картой')->icon('heroicon-o-credit-card')
                ->authorize(Permission::ManagePayments)
                ->visible(fn (Certificate $record) => app(EpayPayments::class)->enabled() && $this->isOwnSaleDraft($record) && ! $record->pendingPayment()
                    && Money::toMinor($record->invoicedAmount()) < Money::toMinor($record->payable_amount))
                ->modalDescription(fn (Certificate $record) => __('Клиент получит письмо со ссылкой на оплату картой (:p1). Оплату подтвердит банк — отмечать вручную не нужно.',
                    ['p1' => Money::format(Money::fromMinor(Money::toMinor($record->payable_amount) - Money::toMinor($record->invoicedAmount())))]))
                ->fillForm(fn (Certificate $record) => ['lang' => $record->onlineOrder?->locale ?? app()->getLocale()])
                ->schema([
                    TextInput::make('email')->label('Email клиента')->email()->maxLength(255)
                        ->helperText(__('Пусто — письмо уйдёт на адрес заказчика из его карточки (он здесь не показывается).')),
                    Select::make('lang')->label('Язык письма')->options(config('gifter.locales'))->required(),
                ])
                ->action(fn (Certificate $record, array $data, Action $action) => $this->run($action,
                    fn () => app(EpayPayments::class)->createLink($record, $data['email'] ?? null, $data['lang']), __('Ссылка на оплату отправлена клиенту'))),

            Action::make('epayResend')->label('Отправить ссылку ещё раз')->icon('heroicon-o-envelope')->color('gray')
                ->authorize(Permission::ManagePayments)
                ->visible(fn (Certificate $record) => $this->isOwnSaleDraft($record) && $record->pendingPayment()?->method === PaymentMethod::HalykEpay)
                ->fillForm(fn (Certificate $record) => ['lang' => $record->onlineOrder?->locale ?? app()->getLocale()])
                ->schema([
                    TextInput::make('email')->label('Email клиента')->email()->maxLength(255)
                        ->helperText(__('Пусто — адрес заказчика из его карточки.')),
                    Select::make('lang')->label('Язык письма')->options(config('gifter.locales'))->required(),
                ])
                ->action(fn (Certificate $record, array $data, Action $action) => $this->run($action,
                    fn () => app(EpayPayments::class)->resend($record->pendingPayment(), $data['email'] ?? null, $data['lang']), __('Ссылка отправлена клиенту ещё раз'))),

            // Ссылку можно передать клиенту самому (мессенджер, звонок), если письмо не дошло.
            Action::make('epayShowLink')->label('Показать ссылку')->icon('heroicon-o-link')->color('gray')
                ->authorize(Permission::ManagePayments)
                ->visible(fn (Certificate $record) => $this->isOwnSaleDraft($record) && $record->pendingPayment()?->method === PaymentMethod::HalykEpay && filled($record->pendingPayment()?->pay_url))
                ->modalHeading(__('Ссылка на оплату картой'))
                ->modalDescription(fn (Certificate $record) => __('Передайте её клиенту любым способом, если письмо не дошло. Ссылка действует до :p1.', ['p1' => $record->pendingPayment()?->pay_expires_at?->format('d.m.Y H:i') ?? '—']))
                ->fillForm(fn (Certificate $record) => ['pay_url' => $record->pendingPayment()?->pay_url])
                ->schema([TextInput::make('pay_url')->label('Ссылка')->readOnly()->copyable(copyMessage: __('Ссылка скопирована'))->dehydrated(false)])
                ->modalSubmitAction(false)->modalCancelActionLabel(__('Закрыть')),

            Action::make('epayCheck')->label('Проверить оплату в банке')->icon('heroicon-o-arrow-path')->color('gray')
                ->authorize(Permission::ManagePayments)
                ->visible(fn (Certificate $record) => $this->isOwnSaleDraft($record) && $record->pendingPayment()?->method === PaymentMethod::HalykEpay)
                ->action(function (Certificate $record, Action $action) {
                    $paid = false;
                    RunsDomainActions::run($action, function () use ($record, &$paid) {
                        $paid = app(EpayPayments::class)->checkNow($record->pendingPayment()) === EpayPayments::PAID;
                    });
                    Notification::make()->{$paid ? 'success' : 'warning'}()->title($paid ? __('Оплата поступила') : __('Оплата пока не поступила'))->send();
                    $this->redirect(CertificateResource::getUrl('view', ['record' => $this->getRecord()]));
                }),

            // Счёт Kaspi на телефон заказчика (через посредника): оплату подтверждает его API, вручную не отмечается.
            Action::make('kaspiInvoice')->label('Счёт в Kaspi')->icon('heroicon-o-device-phone-mobile')
                ->authorize(Permission::ManagePayments)
                ->visible(fn (Certificate $record) => app(KaspiPayments::class)->enabled() && $this->isOwnSaleDraft($record) && ! $record->pendingPayment()
                    && Money::toMinor($record->invoicedAmount()) < Money::toMinor($record->payable_amount))
                ->requiresConfirmation()
                ->modalDescription(fn (Certificate $record) => __('Клиент получит счёт на :p1 в приложении Kaspi на телефон заказчика. Оплату подтвердит сервис — отмечать вручную не нужно.',
                    ['p1' => Money::format(Money::fromMinor(Money::toMinor($record->payable_amount) - Money::toMinor($record->invoicedAmount())))]))
                ->action(fn (Certificate $record, Action $action) => $this->run($action,
                    fn () => app(KaspiPayments::class)->createInvoice($record), __('Счёт отправлен клиенту в Kaspi'))),

            Action::make('kaspiCheck')->label('Проверить оплату в Kaspi')->icon('heroicon-o-arrow-path')->color('gray')
                ->authorize(Permission::ManagePayments)
                ->visible(fn (Certificate $record) => $this->isOwnSaleDraft($record) && $record->pendingPayment()?->method === PaymentMethod::KaspiPay)
                ->action(function (Certificate $record, Action $action) {
                    $paid = false;
                    RunsDomainActions::run($action, function () use ($record, &$paid) {
                        $paid = app(KaspiPayments::class)->checkNow($record->pendingPayment()) === KaspiPayments::PAID;
                    });
                    Notification::make()->{$paid ? 'success' : 'warning'}()->title($paid ? __('Оплата поступила') : __('Оплата пока не поступила'))->send();
                    $this->redirect(CertificateResource::getUrl('view', ['record' => $this->getRecord()]));
                }),

            Action::make('issue')->label('Выпустить')->icon('heroicon-o-check-badge')->color('success')
                ->authorize(Permission::IssueCertificates)
                // Кнопка появляется, когда выпуск возможен: продажа оплачена, бесплатный согласован.
                ->visible(fn (Certificate $record) => ! $record->batch_id && $record->isReadyToIssue())
                ->requiresConfirmation()
                ->modalDescription(__('После выпуска состав и цены фиксируются окончательно.'))
                ->action(fn (Certificate $record, Action $action) => $this->run($action, fn () => $this->manager()->issue($record), __('Сертификат выпущен'))),

            Action::make('downloadPdf')->label('PDF')->icon('heroicon-o-arrow-down-tray')->color('gray')
                ->visible(fn (Certificate $record) => $record->isPrintable())
                ->action(function (Certificate $record) {
                    $documents = app(CertificateDocument::class);
                    $pdf = $documents->download($record);

                    return response()->streamDownload(fn () => print ($pdf), $documents->filename($record), ['Content-Type' => 'application/pdf']);
                }),

            Action::make('emailPdf')->label('Отправить на email')->icon('heroicon-o-envelope')->color('gray')
                ->authorize(Permission::IssueCertificates)
                ->visible(fn (Certificate $record) => $record->isPrintable())
                ->modalDescription(__('Гость получит письмо с PDF сертификата и ссылкой проверки. Отправка записывается в историю.'))
                ->fillForm(fn (Certificate $record) => ['lang' => $record->onlineOrder?->locale ?? app()->getLocale()])
                ->schema([
                    TextInput::make('email')->label('Email')->email()->maxLength(255)
                        ->helperText(__('Пусто — адрес из заявки или карточки заказчика (контакты здесь не показываются).')),
                    Select::make('lang')->label('Язык письма и PDF')->options(config('gifter.locales'))->required(),
                ])
                ->action(fn (Certificate $record, array $data, Action $action) => $this->run($action,
                    fn () => app(CertificateMailer::class)->send($record, ($data['email'] ?? null) ?: (string) app(CertificateMailer::class)->defaultEmail($record), $data['lang']), __('Сертификат отправлен на email'))),

            $this->reservationAction('reserve', 'Отметить бронь', [CertificateStatus::Active]),
            $this->checkInAction(),
            // Решение 27.10.2026: погасить можно и с невыполненными заявками — они закроются как отменённые.
            $this->transitionAction('redeem', 'Отметить использованным', CertificateStatus::InUse, CertificateStatus::Used, 'heroicon-o-check-circle', Permission::RedeemCertificates,
                function (Certificate $record) {
                    $open = $record->fulfillments()->whereIn('status', FulfillmentStatus::open())->with('item')->get();

                    return __('Сертификат используется целиком, со всеми услугами. Отменить отметку «Использован» нельзя.')
                        .($record->reservation_review_status === ReservationReview::Pending ? __(' Бронь ещё на проверке Финансов.') : '')
                        .($open->isEmpty() ? '' : __(' Внимание: не выполнены :p1 — эти заявки закроются как отменённые.', ['p1' => $open->map(fn ($f) => "{$f->serviceLabel()} ({$f->status->getLabel()})")->implode(', ')]));
                }),

            ActionGroup::make([
                Action::make('setRecipient')->label(fn (Certificate $record) => $record->recipient_id ? __('Сменить получателя') : __('Указать получателя'))
                    ->icon('heroicon-o-user-plus')
                    ->authorize(Permission::AssignRecipient)
                    ->visible(fn (Certificate $record) => ! $record->isDraft() && ! $record->status->isFinal()
                        && ! in_array($record->status, [CertificateStatus::InUse, CertificateStatus::Reserved], true))
                    ->fillForm(fn (Certificate $record) => ['recipient_id' => $record->recipient_id])
                    ->schema([
                        Select::make('recipient_id')->label('Получатель')->required()
                            ->options(fn () => Recipient::query()->orderBy('last_name')->pluck('full_name', 'id'))->searchable()
                            ->createOptionForm(RecipientForm::fields())
                            ->createOptionUsing(fn (array $data) => Recipient::create($data)->getKey()),
                    ])
                    ->action(fn (Certificate $record, array $data, Action $action) => $this->run($action,
                        fn () => $this->manager()->setRecipient($record, (int) $data['recipient_id']), __('Получатель указан'))),
                ContactFields::revealAction('revealCustomer', fn (Certificate $record) => $record->customer, __('Контакты заказчика')),
                ContactFields::revealAction('revealRecipient', fn (Certificate $record) => $record->recipient, __('Контакты получателя')),

                $this->reservationAction('changeReservation', 'Изменить бронь', [CertificateStatus::Reserved, CertificateStatus::InUse]),
                Action::make('release')->label('Снять бронь')->icon('heroicon-o-arrow-uturn-left')
                    ->authorize(Permission::ManageReservations)
                    ->visible(fn (Certificate $record) => $record->status === CertificateStatus::Reserved)
                    ->modalDescription(fn (Certificate $record) => __('Бронь :p1 будет снята, сертификат снова станет активным. Данные брони останутся в истории.', ['p1' => $record->reservationLabel()]))
                    ->schema([Textarea::make('reason')->label('Причина')->required()])
                    ->action(fn (Certificate $record, array $data, Action $action) => $this->run($action,
                        fn () => app(ReservationManager::class)->release($record, $data['reason']), __('Бронь снята'))),

                Action::make('approveReservation')->label('Бронь проверена')->icon('heroicon-o-shield-check')->color('success')
                    ->authorize(Permission::ReviewReservations)
                    ->visible(fn (Certificate $record) => in_array($record->reservation_review_status, [ReservationReview::Pending, ReservationReview::Disputed], true))
                    ->modalDescription(fn (Certificate $record) => self::reviewSummary($record))
                    ->schema([Textarea::make('comment')->label('Комментарий')])
                    ->action(fn (Certificate $record, array $data, Action $action) => $this->run($action,
                        fn () => app(ReservationManager::class)->approve($record, $data['comment'] ?? null), __('Бронь подтверждена'))),
                Action::make('disputeReservation')->label('Оспорить бронь')->icon('heroicon-o-shield-exclamation')->color('danger')
                    ->authorize(Permission::ReviewReservations)
                    ->visible(fn (Certificate $record) => in_array($record->reservation_review_status, [ReservationReview::Pending, ReservationReview::Approved], true)
                        && ! $record->status->isFinal())
                    ->modalDescription(fn (Certificate $record) => self::reviewSummary($record).' '.__('Пока спор не снят, отметить сертификат использованным нельзя.'))
                    ->schema([Textarea::make('comment')->label('Что не так с бронью')->required()])
                    ->action(fn (Certificate $record, array $data, Action $action) => $this->run($action,
                        fn () => app(ReservationManager::class)->dispute($record, $data['comment']), __('Бронь оспорена'))),

                Action::make('changeValidity')->label('Изменить срок')->icon('heroicon-o-clock')
                    ->authorize(Permission::ChangeValidity)
                    ->visible(fn (Certificate $record) => ! $record->isDraft() && in_array($record->status, [CertificateStatus::Active, CertificateStatus::Reserved], true))
                    ->fillForm(fn (Certificate $record) => ['valid_from' => $record->valid_from, 'valid_until' => $record->valid_until])
                    ->schema([
                        DatePicker::make('valid_from')->label('Действует с')->required(),
                        DatePicker::make('valid_until')->label('Действует по')->required()->afterOrEqual('valid_from'),
                        Textarea::make('reason')->label('Причина')->required(),
                    ])
                    ->action(fn (Certificate $record, array $data, Action $action) => $this->run($action, fn () => $this->manager()
                        ->changeValidity($record, Carbon::parse($data['valid_from']), Carbon::parse($data['valid_until']), $data['reason']), __('Срок изменён'))),

                Action::make('cancel')->label('Аннулировать')->icon('heroicon-o-no-symbol')->color('danger')
                    ->authorize(Permission::CancelCertificates)
                    ->visible(fn (Certificate $record) => $record->status->canTransitionTo(CertificateStatus::Cancelled)
                        && Money::toMinor($record->paidAmount()) === 0 && ! $record->hasPendingRefund()
                        && ! ($record->isDraft() && $record->batch_id))
                    ->schema([Textarea::make('reason')->label('Причина')->required()])
                    ->modalDescription(__('Сертификат останется в реестре со статусом «Аннулирован» — удалить его нельзя.'))
                    ->action(fn (Certificate $record, array $data, Action $action) => $this->run($action, fn () => $this->manager()->cancel($record, $data['reason']), __('Сертификат аннулирован'))),

                Action::make('requestRefund')->label('Возврат и аннулирование')->icon('heroicon-o-receipt-refund')->color('danger')
                    ->authorize(Permission::RequestRefunds)
                    ->visible(fn (Certificate $record) => $record->status->canTransitionTo(CertificateStatus::Cancelled)
                        && Money::toMinor($record->paidAmount()) > 0 && ! $record->hasPendingRefund())
                    ->modalDescription(__('Запрос уйдёт на согласование Финансам. До решения сертификат заблокирован; после согласования он будет аннулирован.'))
                    ->fillForm(fn (Certificate $record) => ['amount' => $record->paidAmount()])
                    ->schema([
                        TextInput::make('amount')->label('Сумма к возврату, ₸')->numeric()->minValue(0)->required()
                            ->helperText(__('От 0 до суммы поступивших оплат.')),
                        Textarea::make('reason')->label('Причина и с кем согласовано')->required(),
                    ])
                    ->action(fn (Certificate $record, array $data, Action $action) => $this->run($action, fn () => app(RefundManager::class)
                        ->request($record, (string) $data['amount'], $data['reason']), __('Запрос на возврат отправлен Финансам'))),
            ])->label('Ещё')->button()->color('gray'),

            ActionGroup::make([
                Action::make('adminSetStatus')->label('Исправить статус')->icon('heroicon-o-wrench-screwdriver')
                    ->authorize(Permission::AdminCorrections)
                    ->modalDescription(__('Только для исправления ошибок: обычные правила смены статуса не действуют. История сохранится, исправление запишется отдельно.'))
                    ->schema([
                        Select::make('status')->label('Новый статус')->options(CertificateStatus::class)->required(),
                        Textarea::make('reason')->label('Причина')->required(),
                    ])
                    ->action(fn (Certificate $record, array $data, Action $action) => $this->run($action, fn () => app(AdminCorrections::class)
                        ->setStatus($record, $data['status'] instanceof CertificateStatus ? $data['status'] : CertificateStatus::from($data['status']), $data['reason']), __('Статус исправлен'))),
                Action::make('adminRevokeApproval')->label('Отозвать согласование')->icon('heroicon-o-hand-thumb-down')
                    ->authorize(Permission::AdminCorrections)
                    ->visible(fn (Certificate $record) => $record->isDraft() && $record->isComplimentaryApproved())
                    ->schema([Textarea::make('reason')->label('Причина')->required()])
                    ->action(fn (Certificate $record, array $data, Action $action) => $this->run($action, fn () => app(AdminCorrections::class)
                        ->revokeComplimentaryApproval($record, $data['reason']), __('Согласование отозвано'))),
            ])->label('Исправление')->icon('heroicon-o-wrench-screwdriver')->button()->color('danger')
                ->visible(fn () => auth()->user()?->can(Permission::AdminCorrections)),
        ];
    }

    /** Форма брони: номер PMS, гость как в брони, даты; имя сверяется сразу, при несовпадении нужна причина. */
    private function reservationAction(string $name, string $label, array $statuses): Action
    {
        $reservations = fn (): ReservationManager => app(ReservationManager::class);
        $pmsActive = fn (): bool => PmsClient::make()->isActive();
        $needsNote = function (Certificate $record, Get $get) use ($reservations, $pmsActive): bool {
            $risk = $record->status === CertificateStatus::Active && $reservations()->inRiskWindow($record);
            if ($pmsActive()) {
                try {
                    $found = $reservations()->resolveFromPms($record, (string) $get('booking_number'));
                } catch (PmsException) {
                    return false;
                }

                return $risk || collect($found['checks'])->contains(fn (array $c) => ! $c['ok'] && $c['key'] !== 'arrival');
            }

            return filled($get('guest_name')) && ($reservations()->matchName($record, (string) $get('guest_name')) === NameMatch::Mismatch || $risk);
        };

        return Action::make($name)->label($label)->icon('heroicon-o-calendar')
            ->color($name === 'reserve' ? 'primary' : 'gray')
            ->authorize(Permission::ManageReservations)
            ->visible(fn (Certificate $record) => in_array($record->status, $statuses, true))
            ->modalDescription(fn () => $pmsActive()
                ? __('Введите номер брони PMS — гость и даты подтянутся из PMS автоматически. Без подтверждения PMS бронь не сохраняется.')
                : __('Бронь заводится в PMS; сюда вносятся её номер, гость и даты. Имя гостя сверяется с получателем и заказчиком.'))
            ->modalSubmitActionLabel(__('Сохранить бронь'))
            ->fillForm(fn (Certificate $record) => [
                'booking_number' => $record->pms_booking_number,
                'guest_name' => $record->booking_guest_name ?? trim($record->recipient?->last_name.' '.$record->recipient?->first_name),
                'arrival' => $record->arrival_date,
                'departure' => $record->departure_date,
                'note' => $record->reservation_note,
            ])
            ->schema([
                TextInput::make('booking_number')->label('Номер брони PMS')->required()->maxLength(64)->live(onBlur: true),
                Placeholder::make('pms_preview')->hiddenLabel()->visible($pmsActive)
                    ->content(fn (Certificate $record, Get $get) => self::pmsPreview($record, (string) $get('booking_number'))),
                TextInput::make('guest_name')->label('Гость, как в брони')->required(fn () => ! $pmsActive())->maxLength(255)->live(onBlur: true)
                    ->visible(fn () => ! $pmsActive())
                    ->helperText(function (Certificate $record, Get $get) use ($reservations): ?string {
                        if (blank($get('guest_name'))) {
                            return null;
                        }

                        return $reservations()->matchName($record, (string) $get('guest_name'))->getLabel()
                            .' ('.__('получатель: :p1, заказчик: :p2', ['p1' => $record->recipient?->full_name ?? '—', 'p2' => $record->customer?->displayName() ?: '—']).')';
                    }),
                Grid::make(2)->visible(fn () => ! $pmsActive())->schema([
                    DatePicker::make('arrival')->label('Заезд')->required(fn () => ! $pmsActive())
                        ->minDate(fn (Certificate $record) => $record->valid_from)->maxDate(fn (Certificate $record) => $record->valid_until),
                    DatePicker::make('departure')->label('Выезд')->required(fn () => ! $pmsActive())->after('arrival'),
                ]),
                Textarea::make('note')->label(fn (Certificate $record, Get $get) => ($pmsActive() ? $this->hasDiscrepancy($record, $get) : $reservations()->matchName($record, (string) $get('guest_name')) === NameMatch::Mismatch)
                    ? ($pmsActive() ? __('Причина расхождений') : __('Причина несовпадения имени')) : __('Комментарий: бронь в последние дни срока'))
                    ->helperText(__('Бронь уйдёт на проверку Финансам.'))
                    ->visible($needsNote)->required($needsNote),
            ])
            ->action(fn (Certificate $record, array $data, Action $action) => $this->run($action,
                fn () => $reservations()->save($record, $data), $name === 'reserve' ? __('Бронь отмечена') : __('Бронь изменена')));
    }

    /** Сверка с PMS нашла расхождение (имя, выезд за срок, тариф), требующее причины. */
    private function hasDiscrepancy(Certificate $record, Get $get): bool
    {
        try {
            $found = app(ReservationManager::class)->resolveFromPms($record, (string) $get('booking_number'));
        } catch (PmsException) {
            return false;
        }

        return collect($found['checks'])->contains(fn (array $c) => ! $c['ok'] && $c['key'] !== 'arrival');
    }

    /** Что нашла PMS по номеру брони и результат сверки с сертификатом; или причина, почему бронь нельзя привязать. */
    private static function pmsPreview(Certificate $record, string $number): HtmlString|string
    {
        if (blank($number)) {
            return __('После ввода номера здесь появятся данные брони из PMS.');
        }
        try {
            $found = app(ReservationManager::class)->resolveFromPms($record, $number);
        } catch (PmsException $e) {
            return new HtmlString('<span class="text-danger-600">'.e($e->getMessage()).'</span>');
        }
        $b = $found['booking'];
        $stale = $b->stale ? ' <span class="text-warning-600">'.e(__('(данные PMS устарели: :p1 мин)', ['p1' => $b->dataAgeMinutes ?? '?'])).'</span>' : '';
        $lines = collect($found['checks'])->map(fn (array $c) => '<div class="'.($c['ok'] ? 'text-success-600' : 'text-danger-600').'">'.($c['ok'] ? '✓ ' : '✗ ').e($c['text']).'</div>')->implode('');

        return new HtmlString('<div><strong>'.e($found['guest']).'</strong> · '.$b->arrival->format('d.m.Y').' — '.$b->departure->format('d.m.Y').' · '.e(__('комнат: :p1', ['p1' => $b->roomCount])).$stale.'</div>'
            .$lines.'<div class="gf-muted">'.e(__('Получатель: :p1, заказчик: :p2', ['p1' => $record->recipient?->full_name ?? '—', 'p2' => $record->customer?->displayName() ?: '—'])).'</div>');
    }

    private static function reviewSummary(Certificate $record): string
    {
        return __('Бронь :p1. :p2.', ['p1' => $record->reservationLabel(), 'p2' => $record->name_match?->getLabel()])
            .($record->reservation_note ? ' '.__('Причина: :p1.', ['p1' => $record->reservation_note]) : '')
            .' '.__('Получатель: :p1, заказчик: :p2', ['p1' => $record->recipient?->full_name ?? '—', 'p2' => $record->customer?->displayName() ?: '—']).'.';
    }

    private function transitionAction(string $name, string $label, CertificateStatus $from, CertificateStatus $to, string $icon, Permission $permission, string|Closure|null $warning = null): Action
    {
        return Action::make($name)->label($label)->icon($icon)
            ->authorize($permission)
            ->visible(fn (Certificate $record) => $record->status === $from)
            ->requiresConfirmation()
            ->modalDescription($warning)
            ->schema([Textarea::make('reason')->label('Комментарий')])
            ->action(fn (Certificate $record, array $data, Action $action) => $this->run($action,
                fn () => $this->manager()->transition($record, $to, $data['reason'] ?? null), "Статус: {$to->getLabel()}"));
    }

    /**
     * Заезд. Бронь подтверждена по PMS — Gifter повторно запрашивает PMS и отмечает заезд сам (без комментария);
     * расхождение — заезд не отмечается, бронь уходит на проверку Финансам. Иначе — отметка вручную.
     */
    private function checkInAction(): Action
    {
        $viaPms = fn (Certificate $record): bool => app(ArrivalSync::class)->applies($record);

        return Action::make('checkIn')->label('Заезд')->icon('heroicon-o-arrow-right-end-on-rectangle')
            ->authorize(Permission::ManageReservations)
            ->visible(fn (Certificate $record) => $record->status === CertificateStatus::Reserved)
            ->requiresConfirmation()
            ->modalDescription(fn (Certificate $record) => $viaPms($record)
                ? __('Gifter запросит бронь № :p1 в PMS, убедится, что она не изменилась, и отметит заезд. Вводить ничего не нужно.', ['p1' => $record->pms_booking_number])
                : null)
            ->schema(fn (Certificate $record) => $viaPms($record) ? [] : [Textarea::make('reason')->label('Комментарий')])
            ->action(fn (Certificate $record, array $data, Action $action) => $this->run($action,
                function () use ($viaPms, $record, $data) {
                    if (! $viaPms($record)) {
                        $this->manager()->transition($record, CertificateStatus::InUse, $data['reason'] ?? null);

                        return;
                    }
                    try {
                        app(ArrivalSync::class)->checkIn($record);
                    } catch (DomainRuleViolation $e) {
                        throw $e;
                    } catch (\Throwable $e) {
                        report($e);
                        throw new DomainRuleViolation(__('Не удалось проверить заезд в PMS, попробуйте позже. Заезд не отмечен.'));
                    }
                },
                'Статус: '.CertificateStatus::InUse->getLabel()));
    }

    private function run(Action $action, callable $callback, string $success): void
    {
        RunsDomainActions::run($action, $callback, $success);
        $this->redirect(CertificateResource::getUrl('view', ['record' => $this->getRecord()]));
    }

    /** Черновик не из группы с частью, которую оплачивает заказчик: вся продажа или платные доп. услуги бесплатного. */
    private function isOwnSaleDraft(Certificate $record): bool
    {
        return $record->isDraft() && ($record->hasPayablePart() || ! $record->isComplimentary()) && ! $record->batch_id;
    }

    private function manager(): CertificateManager
    {
        return app(CertificateManager::class);
    }
}
