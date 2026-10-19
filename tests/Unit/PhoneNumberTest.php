<?php

namespace Tests\Unit;

use App\Support\PhoneNumber;
use PHPUnit\Framework\TestCase;

class PhoneNumberTest extends TestCase
{
    public function test_international_format(): void
    {
        $this->assertSame('+77011234567', PhoneNumber::normalize('+7 (701) 123-45-67'));
        $this->assertSame('+77011234567', PhoneNumber::normalize('8 701 123 45 67'));
        $this->assertSame('+996555123456', PhoneNumber::normalize('+996 555 123 456'));
        $this->assertSame('+7 701 123 45 67', PhoneNumber::format('87011234567'));
        $this->assertNull(PhoneNumber::normalize('  '));

        foreach (['+77011234567', '+996555123456', '+44 20 7946 0958', '8 701 123 45 67', null, ''] as $ok) {
            $this->assertTrue(PhoneNumber::isValid($ok), (string) $ok);
        }
        foreach (['64564556', '7011234567', '+7 701', '+0 701 123 45 67', '+7 701 123 45 67 89 01 23', 'тел. 123'] as $bad) {
            $this->assertFalse(PhoneNumber::isValid($bad), $bad);
        }
    }
}
