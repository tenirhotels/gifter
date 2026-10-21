<?php

namespace App\Services\Epay;

use RuntimeException;

/** Сбой обмена с банком: нет связи, отказ, неожиданный ответ. Текст безопасен для показа сотруднику (без ключей). */
class EpayException extends RuntimeException {}
