<?php

namespace App\Services\KaspiYespay;

use RuntimeException;

class KaspiYespayException extends RuntimeException
{
    /** Кассир Kaspi отключился: нужен вход человека в кабинете посредника. */
    public const REAUTH = 'payment_account_reauth_required';

    public function __construct(string $message, public readonly ?string $errorCode = null)
    {
        parent::__construct($message);
    }

    public function needsReauth(): bool
    {
        return $this->errorCode === self::REAUTH;
    }
}
