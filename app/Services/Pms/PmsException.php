<?php

namespace App\Services\Pms;

use App\Exceptions\DomainRuleViolation;

/** Бронь нельзя подтвердить по PMS; текст — для сотрудника. */
class PmsException extends DomainRuleViolation {}
