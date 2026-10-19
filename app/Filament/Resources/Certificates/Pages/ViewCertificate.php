<?php

namespace App\Filament\Resources\Certificates\Pages;

use App\Enums\CertificateStatus;
use App\Enums\FulfillmentStatus;
use App\Enums\NameMatch;
use App\Enums\PaymentMethod;
use App\Enums\Permission;
use App\Enums\ReservationReview;
use App\Filament\Resources\CertificateBatches\CertificateBatchResource;
use App\Filament\Resources\Certificates\CertificateResource;
use App\Filament\Resources\Recipients\Schemas\RecipientForm;
use App\Filament\Support\ContactFields;
use App\Filament\Support\RunsDomainActions;
use App\Filament\Support\WithoutPageHeading;
use App\Models\Certificate;
use App\Models\Recipient;
use App\Models\User;
use App\Services\AdminCorrections;
use App\Services\CertificateDocument;
use App\Services\CertificateManager;
use App\Services\PaymentManager;
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
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Support\Carbon;

class ViewCertificate extends ViewRecord
{
    use WithoutPageHeading;

    protected static string $resource = CertificateResource::class;

    public function getTitle(): string
    {
        return 'Сертификат '.$this->getRecord()->number;
    }

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make()->label('Реквизиты')->visible(fn (Certificate $record) => $record->isDraft()),

            // У черновика из группы счёт, оплата, согласование, состав и выпуск — на её странице.
            Action::make('openBatch')->icon('heroicon-o-rectangle-stack')
                ->label(fn (Certificate $record) => $record->batch->shortLabel().($record->isDraft() ? ($record->batch->isComplimentary() ? ': согласование, выпуск' : ': счёт, оплата, выпуск') : ''))
                ->color(fn (Certificate $record) => $record->isDraft() ? 'primary' : 'gray')
                ->visible(fn (Certificate $record) => $record->batch_id && auth()->user()?->can('view', $record->batch))
                ->url(fn (Certificate $record) => CertificateBatchResource::getUrl('view', ['record' => $record->batch_id])),

            // Истёк без брони: бронь на свободную дату — заявка Front Office, согласует директор по продажам.
            Action::make('requestException')->label('Бронь по исключению')->icon('heroicon-o-calendar-days')->color('warning')
                ->authorize(Permission::RequestReservationException)
                ->visible(fn (Certificate $record) => $record->status === CertificateStatus::Expired && ! $record->hasPendingException())
                ->modalDescription('Сертификат истёк без брони. Выберите свободную и доступную дату и внесите бронь PMS — заявку согласует директор по продажам. После согласования сертификат вернётся в работу до даты выезда.')
                ->modalSubmitActionLabel('Отправить директору')
                ->fillForm(fn (Certificate $record) => ['guest_name' => trim($record->recipient?->last_name.' '.$record->recipient?->first_name)])
                ->schema([
                    TextInput::make('booking_number')->label('Номер брони PMS')->required()->maxLength(64),
                    TextInput::make('guest_name')->label('Гость, как в брони')->required()->maxLength(255),
                    Grid::make(2)->schema([
                        DatePicker::make('arrival')->label('Заезд')->required()->minDate(today()),
                        DatePicker::make('departure')->label('Выезд')->required()->after('arrival'),
                    ]),
                    Textarea::make('reason')->label('Причина исключения')->required()->helperText('Что произошло и почему бронь не была поставлена вовремя.'),
                ])
                ->action(fn (Certificate $record, array $data, Action $action) => $this->run($action,
                    fn () => app(ReservationExceptionManager::class)->request($record, $data), 'Заявка отправлена директору')),
            Action::make('approveException')->label('Согласовать бронь по исключению')->icon('heroicon-o-hand-thumb-up')->color('success')
                ->authorize(Permission::ApproveReservationException)
                ->visible(fn (Certificate $record) => $record->status === CertificateStatus::Expired && $record->hasPendingException())
                ->modalDescription(function (Certificate $record) {
                    $e = $record->reservationExceptions()->where('status', 'pending')->latest('id')->first();

                    return $e ? "Бронь № {$e->booking_number}, {$e->arrival_date->format('d.m')}–{$e->departure_date->format('d.m.Y')}, гость {$e->guest_name}. Причина: {$e->reason}" : null;
                })
                ->schema([Textarea::make('comment')->label('Комментарий')])
                ->action(fn (Certificate $record, array $data, Action $action) => $this->run($action,
                    fn () => app(ReservationExceptionManager::class)->approve($record->reservationExceptions()->where('status', 'pending')->latest('id')->firstOrFail(), $data['comment'] ?? null),
                    'Исключение согласовано, сертификат возвращён в работу')),
            Action::make('rejectException')->label('Отклонить бронь по исключению')->icon('heroicon-o-hand-thumb-down')->color('danger')
                ->authorize(Permission::ApproveReservationException)
                ->visible(fn (Certificate $record) => $record->status === CertificateStatus::Expired && $record->hasPendingException())
                ->schema([Textarea::make('comment')->label('Причина отказа')->required()])
                ->action(fn (Certificate $record, array $data, Action $action) => $this->run($action,
                    fn () => app(ReservationExceptionManager::class)->reject($record->reservationExceptions()->where('status', 'pending')->latest('id')->firstOrFail(), $data['comment']), 'Заявка отклонена')),

            Action::make('setSeller')->label('Продавец')->icon('heroicon-o-user-circle')->color('gray')
                ->authorize(Permission::AssignSeller)
                ->visible(fn (Certificate $record) => $record->isDraft() && ! $record->isComplimentary() && ! $record->batch_id)
                ->modalDescription('Комиссия за погашение достанется выбранному продавцу. После выпуска продавец закрепляется навсегда.')
                ->fillForm(fn (Certificate $record) => ['seller' => $record->sold_by])
                ->schema([Select::make('seller')->label('Продавец')->required()->searchable()->options(fn () => User::sellerOptions())])
                ->action(fn (Certificate $record, array $data, Action $action) => $this->run($action,
                    fn () => $this->manager()->setSeller($record, (int) $data['seller']), 'Продавец назначен')),

            Action::make('approveComplimentary')->label('Согласовать бесплатный')->icon('heroicon-o-hand-thumb-up')->color('success')
                ->authorize(Permission::ApproveComplimentary)
                ->visible(fn (Certificate $record) => $record->isDraft() && $record->isComplimentary() && ! $record->isComplimentaryApproved() && ! $record->batch_id)
                ->modalDescription(fn (Certificate $record) => 'Срок: '.$record->valid_from->format('d.m.Y').' — '.$record->valid_until->format('d.m.Y')
                    .($record->custom_validity ? ' (задан вручную)' : '').'. Маркетинговый расход по себестоимости: '.Money::format($record->marketing_cost)
                    .($record->hasUnknownCost() ? ' (у части услуг себестоимость не указана)' : '')
                    .($record->hasPayablePart() ? '. Платные услуги оплачивает заказчик: '.Money::format($record->payable_amount).' (выпуск — после оплаты)' : '')
                    .'. После согласования состав фиксируется.')
                ->schema([Textarea::make('comment')->label('Комментарий')])
                ->action(fn (Certificate $record, array $data, Action $action) => $this->run($action,
                    fn () => $this->manager()->approveComplimentary($record, $data['comment'] ?? null), 'Бесплатный сертификат согласован')),

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
                    Select::make('method')->label('Способ')->options(PaymentMethod::class)->required(),
                    TextInput::make('amount')->label('Сумма, ₸')->numeric()->minValue(1)->required()->helperText('По умолчанию — весь неоплаченный остаток.'),
                    TextInput::make('invoice_number')->label('№ счёта в банке')->maxLength(255),
                ])
                ->action(fn (Certificate $record, array $data, Action $action) => $this->run($action, fn () => app(PaymentManager::class)->invoice(
                    $record, $data['method'] instanceof PaymentMethod ? $data['method'] : PaymentMethod::from($data['method']),
                    (string) $data['amount'], $data['invoice_number'] ?? null), 'Счёт выставлен')),

            Action::make('markPaid')->label('Оплачен')->icon('heroicon-o-check')->color('success')
                ->visible(fn (Certificate $record) => $this->isOwnSaleDraft($record) && $record->pendingPayment() !== null)
                ->authorize(fn (Certificate $record) => auth()->user()->can($record->pendingPayment()?->method->requiresFinanceConfirmation()
                    ? Permission::ReceiveBankTransfers : Permission::ReceivePayments))
                ->modalDescription(fn (Certificate $record) => ($line = $record->pendingPayment())
                    ? $line->method->getLabel().($line->invoice_number ? ' № '.$line->invoice_number : '').' · '.Money::format($line->amount) : null)
                ->schema([DateTimePicker::make('paid_at')->label('Дата поступления')->default(now())->maxDate(now())->required()])
                ->action(fn (Certificate $record, array $data, Action $action) => $this->run($action,
                    fn () => app(PaymentManager::class)->markPaid($record->pendingPayment(), Carbon::parse($data['paid_at'])), 'Оплата отмечена')),

            Action::make('issue')->label('Выпустить')->icon('heroicon-o-check-badge')->color('success')
                ->authorize(Permission::IssueCertificates)
                // Кнопка появляется, когда выпуск возможен: продажа оплачена, бесплатный согласован.
                ->visible(fn (Certificate $record) => ! $record->batch_id && $record->isReadyToIssue())
                ->requiresConfirmation()
                ->modalDescription('После выпуска состав и цены фиксируются окончательно.')
                ->action(fn (Certificate $record, Action $action) => $this->run($action, fn () => $this->manager()->issue($record), 'Сертификат выпущен')),

            Action::make('downloadPdf')->label('PDF')->icon('heroicon-o-arrow-down-tray')->color('gray')
                ->visible(fn (Certificate $record) => $record->isPrintable())
                ->action(function (Certificate $record) {
                    $documents = app(CertificateDocument::class);
                    $pdf = $documents->download($record);

                    return response()->streamDownload(fn () => print ($pdf), $documents->filename($record), ['Content-Type' => 'application/pdf']);
                }),

            $this->reservationAction('reserve', 'Отметить бронь', [CertificateStatus::Active]),
            $this->transitionAction('checkIn', 'Заезд', CertificateStatus::Reserved, CertificateStatus::InUse, 'heroicon-o-arrow-right-end-on-rectangle', Permission::ManageReservations),
            // Решение 27.10.2026: погасить можно и с невыполненными заявками — они закроются как отменённые.
            $this->transitionAction('redeem', 'Погасить', CertificateStatus::InUse, CertificateStatus::Used, 'heroicon-o-check-circle', Permission::RedeemCertificates,
                function (Certificate $record) {
                    $open = $record->fulfillments()->whereIn('status', FulfillmentStatus::open())->with('item')->get();

                    return 'Сертификат погашается целиком, со всеми услугами. Отменить погашение нельзя.'
                        .($record->reservation_review_status === ReservationReview::Pending ? ' Бронь ещё на проверке Финансов.' : '')
                        .($open->isEmpty() ? '' : ' Внимание: не выполнены '.$open->map(fn ($f) => "{$f->serviceLabel()} ({$f->status->getLabel()})")->implode(', ')
                            .' — эти заявки закроются как отменённые.');
                }),

            ActionGroup::make([
                Action::make('setRecipient')->label(fn (Certificate $record) => $record->recipient_id ? 'Сменить получателя' : 'Указать получателя')
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
                        fn () => $this->manager()->setRecipient($record, (int) $data['recipient_id']), 'Получатель указан')),
                ContactFields::revealAction('revealCustomer', fn (Certificate $record) => $record->customer, 'Контакты заказчика'),
                ContactFields::revealAction('revealRecipient', fn (Certificate $record) => $record->recipient, 'Контакты получателя'),

                $this->reservationAction('changeReservation', 'Изменить бронь', [CertificateStatus::Reserved, CertificateStatus::InUse]),
                Action::make('release')->label('Снять бронь')->icon('heroicon-o-arrow-uturn-left')
                    ->authorize(Permission::ManageReservations)
                    ->visible(fn (Certificate $record) => $record->status === CertificateStatus::Reserved)
                    ->modalDescription(fn (Certificate $record) => 'Бронь '.$record->reservationLabel().' будет снята, сертификат снова станет активным. Данные брони останутся в истории.')
                    ->schema([Textarea::make('reason')->label('Причина')->required()])
                    ->action(fn (Certificate $record, array $data, Action $action) => $this->run($action,
                        fn () => app(ReservationManager::class)->release($record, $data['reason']), 'Бронь снята')),

                Action::make('approveReservation')->label('Бронь проверена')->icon('heroicon-o-shield-check')->color('success')
                    ->authorize(Permission::ReviewReservations)
                    ->visible(fn (Certificate $record) => in_array($record->reservation_review_status, [ReservationReview::Pending, ReservationReview::Disputed], true))
                    ->modalDescription(fn (Certificate $record) => self::reviewSummary($record))
                    ->schema([Textarea::make('comment')->label('Комментарий')])
                    ->action(fn (Certificate $record, array $data, Action $action) => $this->run($action,
                        fn () => app(ReservationManager::class)->approve($record, $data['comment'] ?? null), 'Бронь подтверждена')),
                Action::make('disputeReservation')->label('Оспорить бронь')->icon('heroicon-o-shield-exclamation')->color('danger')
                    ->authorize(Permission::ReviewReservations)
                    ->visible(fn (Certificate $record) => in_array($record->reservation_review_status, [ReservationReview::Pending, ReservationReview::Approved], true)
                        && ! $record->status->isFinal())
                    ->modalDescription(fn (Certificate $record) => self::reviewSummary($record).' Пока спор не снят, погасить сертификат нельзя.')
                    ->schema([Textarea::make('comment')->label('Что не так')->required()])
                    ->action(fn (Certificate $record, array $data, Action $action) => $this->run($action,
                        fn () => app(ReservationManager::class)->dispute($record, $data['comment']), 'Бронь оспорена')),

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
                        ->changeValidity($record, Carbon::parse($data['valid_from']), Carbon::parse($data['valid_until']), $data['reason']), 'Срок изменён')),

                Action::make('cancel')->label('Аннулировать')->icon('heroicon-o-no-symbol')->color('danger')
                    ->authorize(Permission::CancelCertificates)
                    ->visible(fn (Certificate $record) => $record->status->canTransitionTo(CertificateStatus::Cancelled)
                        && Money::toMinor($record->paidAmount()) === 0 && ! $record->hasPendingRefund()
                        && ! ($record->isDraft() && $record->batch_id))
                    ->schema([Textarea::make('reason')->label('Причина')->required()])
                    ->modalDescription('Сертификат не удаляется: он остаётся в реестре со статусом «Аннулирован».')
                    ->action(fn (Certificate $record, array $data, Action $action) => $this->run($action, fn () => $this->manager()->cancel($record, $data['reason']), 'Сертификат аннулирован')),

                Action::make('requestRefund')->label('Возврат и аннулирование')->icon('heroicon-o-receipt-refund')->color('danger')
                    ->authorize(Permission::RequestRefunds)
                    ->visible(fn (Certificate $record) => $record->status->canTransitionTo(CertificateStatus::Cancelled)
                        && Money::toMinor($record->paidAmount()) > 0 && ! $record->hasPendingRefund())
                    ->modalDescription('Редкий случай. Запрос уйдёт на согласование Финансам; до решения сертификат заблокирован. После согласования он будет аннулирован.')
                    ->fillForm(fn (Certificate $record) => ['amount' => $record->paidAmount()])
                    ->schema([
                        TextInput::make('amount')->label('Сумма к возврату, ₸')->numeric()->minValue(0)->required()
                            ->helperText('От 0 до суммы поступивших оплат.'),
                        Textarea::make('reason')->label('Причина и с кем согласовано')->required(),
                    ])
                    ->action(fn (Certificate $record, array $data, Action $action) => $this->run($action, fn () => app(RefundManager::class)
                        ->request($record, (string) $data['amount'], $data['reason']), 'Запрос на возврат отправлен Финансам')),
            ])->label('Ещё')->button()->color('gray'),

            ActionGroup::make([
                Action::make('adminSetStatus')->label('Исправить статус')->icon('heroicon-o-wrench-screwdriver')
                    ->authorize(Permission::AdminCorrections)
                    ->modalDescription('Для исправления ошибок: обычные правила переходов не действуют. Прежняя история сохраняется, исправление записывается отдельно.')
                    ->schema([
                        Select::make('status')->label('Новый статус')->options(CertificateStatus::class)->required(),
                        Textarea::make('reason')->label('Причина')->required(),
                    ])
                    ->action(fn (Certificate $record, array $data, Action $action) => $this->run($action, fn () => app(AdminCorrections::class)
                        ->setStatus($record, $data['status'] instanceof CertificateStatus ? $data['status'] : CertificateStatus::from($data['status']), $data['reason']), 'Статус исправлен')),
                Action::make('adminRevokeApproval')->label('Отозвать согласование бесплатного')->icon('heroicon-o-hand-thumb-down')
                    ->authorize(Permission::AdminCorrections)
                    ->visible(fn (Certificate $record) => $record->isDraft() && $record->isComplimentaryApproved())
                    ->schema([Textarea::make('reason')->label('Причина')->required()])
                    ->action(fn (Certificate $record, array $data, Action $action) => $this->run($action, fn () => app(AdminCorrections::class)
                        ->revokeComplimentaryApproval($record, $data['reason']), 'Согласование отозвано')),
            ])->label('Исправление')->icon('heroicon-o-wrench-screwdriver')->button()->color('danger')
                ->visible(fn () => auth()->user()?->can(Permission::AdminCorrections)),
        ];
    }

    /** Форма брони: номер PMS, гость как в брони, даты; имя сверяется сразу, при несовпадении нужна причина. */
    private function reservationAction(string $name, string $label, array $statuses): Action
    {
        $reservations = fn (): ReservationManager => app(ReservationManager::class);
        $needsNote = fn (Certificate $record, Get $get): bool => filled($get('guest_name'))
            && ($reservations()->matchName($record, (string) $get('guest_name')) === NameMatch::Mismatch
                || ($record->status === CertificateStatus::Active && $reservations()->inRiskWindow($record)));

        return Action::make($name)->label($label)->icon('heroicon-o-calendar')
            ->color($name === 'reserve' ? 'primary' : 'gray')
            ->authorize(Permission::ManageReservations)
            ->visible(fn (Certificate $record) => in_array($record->status, $statuses, true))
            ->modalDescription('Бронь заводится в PMS; сюда вносятся её номер, гость и даты. Имя гостя сверяется с получателем и заказчиком.')
            ->modalSubmitActionLabel('Сохранить бронь')
            ->fillForm(fn (Certificate $record) => [
                'booking_number' => $record->pms_booking_number,
                'guest_name' => $record->booking_guest_name ?? trim($record->recipient?->last_name.' '.$record->recipient?->first_name),
                'arrival' => $record->arrival_date,
                'departure' => $record->departure_date,
                'note' => $record->reservation_note,
            ])
            ->schema([
                TextInput::make('booking_number')->label('Номер брони PMS')->required()->maxLength(64),
                TextInput::make('guest_name')->label('Гость, как в брони')->required()->maxLength(255)->live(onBlur: true)
                    ->helperText(function (Certificate $record, Get $get) use ($reservations): ?string {
                        if (blank($get('guest_name'))) {
                            return null;
                        }

                        return $reservations()->matchName($record, (string) $get('guest_name'))->getLabel()
                            .' (получатель: '.($record->recipient?->full_name ?? '—').', заказчик: '.($record->customer?->displayName() ?: '—').')';
                    }),
                Grid::make(2)->schema([
                    DatePicker::make('arrival')->label('Заезд')->required()
                        ->minDate(fn (Certificate $record) => $record->valid_from)->maxDate(fn (Certificate $record) => $record->valid_until),
                    DatePicker::make('departure')->label('Выезд')->required()->after('arrival'),
                ]),
                Textarea::make('note')->label(fn (Certificate $record, Get $get) => $reservations()->matchName($record, (string) $get('guest_name')) === NameMatch::Mismatch
                    ? 'Причина несовпадения имени' : 'Комментарий: бронь в последние дни срока')
                    ->helperText('Бронь уйдёт на проверку Финансам.')
                    ->visible($needsNote)->required($needsNote),
            ])
            ->action(fn (Certificate $record, array $data, Action $action) => $this->run($action,
                fn () => $reservations()->save($record, $data), $name === 'reserve' ? 'Бронь отмечена' : 'Бронь изменена'));
    }

    private static function reviewSummary(Certificate $record): string
    {
        return "Бронь {$record->reservationLabel()}. {$record->name_match?->getLabel()}."
            .($record->reservation_note ? " Причина: {$record->reservation_note}." : '')
            .' Получатель: '.($record->recipient?->full_name ?? '—').', заказчик: '.($record->customer?->displayName() ?: '—').'.';
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
