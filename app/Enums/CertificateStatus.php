<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum CertificateStatus: string implements HasColor, HasLabel
{
    case Draft = 'draft';
    case Active = 'active';
    case Reserved = 'reserved';
    case InUse = 'in_use';
    case Used = 'used';
    case Expired = 'expired';
    case Cancelled = 'cancelled';

    public function getLabel(): string
    {
        return match ($this) {
            self::Draft => __('Черновик'),
            self::Active => __('Активен'),
            self::Reserved => __('Забронирован'),
            self::InUse => __('Используется'),
            self::Used => __('Использован'),
            self::Expired => __('Истёк'),
            self::Cancelled => __('Аннулирован'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Active => 'success',
            self::Reserved, self::InUse => 'info',
            self::Used => 'primary',
            self::Expired => 'warning',
            self::Cancelled => 'danger',
        };
    }

    /**
     * Допустимые переходы. Выпуск (DRAFT → ACTIVE) и аннулирование идут
     * через отдельные методы сервиса, потому что у них свои проверки.
     *
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Draft => [self::Active, self::Cancelled],
            self::Active => [self::Reserved, self::Expired, self::Cancelled],
            self::Reserved => [self::Active, self::InUse, self::Cancelled],
            self::InUse => [self::Used],
            self::Used, self::Expired, self::Cancelled => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    public function isFinal(): bool
    {
        return $this->allowedTransitions() === [];
    }
}
