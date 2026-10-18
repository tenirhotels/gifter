<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Нарушение бизнес-правила Gifter. Сообщение показывается пользователю.
 */
class DomainRuleViolation extends RuntimeException {}
