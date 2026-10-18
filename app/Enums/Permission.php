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

    // Деньги
    case ManagePayments = 'payments.manage';
    case ReceivePayments = 'payments.receive';
    case ReceiveBankTransfers = 'payments.receive_bank_transfer';
    case ReconcilePayments = 'payments.reconcile';
    case ViewPaymentRegister = 'payments.register';
    case RequestRefunds = 'refunds.request';
    case ApproveRefunds = 'refunds.approve';

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
            self::ReceivePayments => [...$sales, Role::Finance],
            self::ReceiveBankTransfers, self::ReconcilePayments, self::ApproveRefunds => [Role::Finance],
            self::ViewPaymentRegister => [Role::Finance],
            self::ViewCatalog => [...$sales, Role::SalesDirector],
            self::ManageCatalog, self::ManagePrices, self::ApproveComplimentary => [Role::SalesDirector],
            // Себестоимость и маржа — не продажам (решение 21.10.2026).
            self::ViewCosts => [Role::SalesDirector, Role::Finance],
            self::ManageUsers, self::ViewAuditLog, self::AdminCorrections => [],
        };
    }
}
