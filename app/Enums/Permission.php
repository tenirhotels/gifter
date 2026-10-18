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
    case ManageCustomers = 'customers.manage';

    // Сертификаты
    case ViewCertificates = 'certificates.view';
    case CreateCertificates = 'certificates.create';
    case EditDrafts = 'certificates.edit_draft';
    case ComposeDrafts = 'certificates.compose_draft';
    case ManagePayments = 'certificates.payments';
    case IssueCertificates = 'certificates.issue';
    case ChangeValidity = 'certificates.change_validity';
    case CancelCertificates = 'certificates.cancel';
    case ManageReservations = 'certificates.reservations';
    case RedeemCertificates = 'certificates.redeem';

    // Каталог
    case ViewCatalog = 'catalog.view';
    case ManageCatalog = 'catalog.manage';
    case ManagePrices = 'catalog.prices';

    // Администрирование
    case ManageUsers = 'users.manage';
    case ViewAuditLog = 'audit.view';

    /** @return list<Role> */
    public function roles(): array
    {
        $sales = [Role::Sales, Role::SalesManager];

        return match ($this) {
            self::ViewCustomers => [...$sales, Role::FrontOffice, Role::Finance],
            self::ManageCustomers, self::CreateCertificates, self::EditDrafts, self::ComposeDrafts,
            self::ManagePayments, self::IssueCertificates => $sales,
            self::ViewCertificates => [...$sales, Role::SalesDirector, Role::FrontOffice, Role::Finance],
            self::ChangeValidity, self::CancelCertificates => [Role::SalesManager],
            self::ManageReservations, self::RedeemCertificates => [Role::FrontOffice],
            self::ViewCatalog => [...$sales, Role::SalesDirector],
            self::ManageCatalog, self::ManagePrices => [Role::SalesDirector],
            self::ManageUsers, self::ViewAuditLog => [],
        };
    }
}
