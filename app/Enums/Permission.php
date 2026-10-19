<?php

namespace App\Enums;

/**
 * Единая матрица прав. Gate-способности регистрируются из неё в AppServiceProvider,
 * SUPER_ADMIN проходит любую проверку.
 */
enum Permission: string
{
    // Клиенты и получатели
    case ViewCustomers = 'customers.view';
    /** Открыть скрытые телефон и email. Каждое открытие пишется в журнал аудита. */
    case RevealContacts = 'customers.reveal_contacts';
    case ManageCustomers = 'customers.manage';

    // Сертификаты
    case ViewCertificates = 'certificates.view';
    /** Видеть чужие сертификаты; без этого права — только созданные самим пользователем. */
    case ViewAllCertificates = 'certificates.view_all';
    case CreateCertificates = 'certificates.create';
    case CreateComplimentary = 'certificates.create_complimentary';
    case ApproveComplimentary = 'certificates.approve_complimentary';
    case EditDrafts = 'certificates.edit_draft';
    /** Дата продажи черновика (от неё зависят цены и срок) — не продавцу. */
    case EditSaleDate = 'certificates.edit_sale_date';
    /** Указать или сменить получателя, в т. ч. у выпущенного сертификата. */
    case AssignRecipient = 'certificates.assign_recipient';
    case ComposeDrafts = 'certificates.compose_draft';
    case IssueCertificates = 'certificates.issue';
    case ChangeValidity = 'certificates.change_validity';
    /** Аннулирование без денег (ничего не оплачено). С оплатой — только через возврат. */
    case CancelCertificates = 'certificates.cancel';
    case ManageReservations = 'certificates.reservations';
    case RedeemCertificates = 'certificates.redeem';
    /** Заявки на исполнение услуг: отправить поставщику, назначить время, подтвердить, отметить выполнение. */
    case ManageFulfillments = 'fulfillments.manage';

    // Деньги
    case ManagePayments = 'payments.manage';
    case ReceivePayments = 'payments.receive';
    case ReceiveBankTransfers = 'payments.receive_bank_transfer';
    case ReconcilePayments = 'payments.reconcile';
    case ViewPaymentRegister = 'payments.register';
    case RequestRefunds = 'refunds.request';
    case ApproveRefunds = 'refunds.approve';
    /** Проверка броней с несовпадением имени или в последние дни срока (фаза 6). */
    case ReviewReservations = 'reservations.review';
    /** Бронь по исключению для истёкшего сертификата: запросить (Front Office) и согласовать (директор). */
    case RequestReservationException = 'reservations.exception_request';
    case ApproveReservationException = 'reservations.exception_approve';

    // Комиссии продавцов
    /** Правила комиссии по пакетам (версии). Только администратор. */
    case ManageCommissionRules = 'commissions.rules';
    /** Свои продажи и своя комиссия («Моя мотивация»). */
    case ViewOwnCommissions = 'commissions.view_own';
    /** Комиссии всех продавцов. */
    case ViewAllCommissions = 'commissions.view_all';
    case ApproveCommissions = 'commissions.approve';
    case PayCommissions = 'commissions.pay';
    /** Указать или сменить продавца сертификата в черновике. */
    case AssignSeller = 'certificates.assign_seller';

    // Каталог
    case ViewCatalog = 'catalog.view';
    case ManageCatalog = 'catalog.manage';
    case ManagePrices = 'catalog.prices';
    /** Себестоимость услуг и сертификатов, маржа. */
    case ViewCosts = 'catalog.costs';

    // Администрирование
    /** Исправление ошибок: откат статусов, оплат, решений. Только администратор, всегда с причиной. */
    case AdminCorrections = 'admin.corrections';
    case ManageUsers = 'users.manage';
    case ViewAuditLog = 'audit.view';
    /** Подключение к PMS (Hotel Hub / Exely): адрес, код отеля, токен. Только администратор. */
    case ManageIntegrations = 'integrations.manage';

    /** @return list<Role> */
    public function roles(): array
    {
        $sales = [Role::Sales, Role::SalesManager];

        return match ($this) {
            self::ViewCustomers, self::RevealContacts => [...$sales, Role::FrontOffice, Role::Finance],
            self::AssignRecipient => $sales,
            self::EditSaleDate => [Role::SalesManager],
            self::ManageCustomers, self::CreateCertificates, self::EditDrafts, self::ComposeDrafts,
            self::ManagePayments, self::IssueCertificates => $sales,
            self::ViewCertificates => [...$sales, Role::SalesDirector, Role::FrontOffice, Role::Finance],
            self::ViewAllCertificates => [Role::SalesManager, Role::SalesDirector, Role::FrontOffice, Role::Finance],
            self::CreateComplimentary, self::ChangeValidity, self::CancelCertificates, self::RequestRefunds => [Role::SalesManager],
            self::ManageReservations, self::RedeemCertificates => [Role::FrontOffice],
            // Решение 27.10.2026: заявки ведут Front Office и менеджер продаж.
            self::ManageFulfillments => [Role::FrontOffice, Role::SalesManager],
            self::ReceivePayments => [...$sales, Role::Finance],
            self::ReceiveBankTransfers, self::ReconcilePayments, self::ApproveRefunds, self::ReviewReservations => [Role::Finance],
            self::ViewPaymentRegister => [Role::Finance],
            self::ViewCatalog => [...$sales, Role::SalesDirector],
            self::ManageCatalog, self::ManagePrices, self::ApproveComplimentary => [Role::SalesDirector],
            // Себестоимость и маржа — не продажам (решение 21.10.2026).
            self::ViewCosts => [Role::SalesDirector, Role::Finance],
            self::RequestReservationException => [Role::FrontOffice],
            self::ApproveReservationException => [Role::SalesDirector],
            self::ViewOwnCommissions => $sales,
            self::ViewAllCommissions => [Role::SalesDirector, Role::Finance],
            self::ApproveCommissions => [Role::SalesDirector],
            self::PayCommissions => [Role::Finance],
            self::AssignSeller => [Role::SalesManager],
            self::ManageIntegrations, self::ManageCommissionRules, self::ManageUsers, self::ViewAuditLog, self::AdminCorrections => [],
        };
    }
}
