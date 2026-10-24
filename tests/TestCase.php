<?php

namespace Tests;

use App\Models\Certificate;
use App\Models\Package;
use App\Services\ReservationManager;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /** Язык определяет браузер (LocaleResolver): тестовый «браузер» по умолчанию русский, как привычные проверки текстов. */
    protected function setUp(): void
    {
        parent::setUp();
        $this->withHeader('Accept-Language', 'ru');
        // Большинство проверок опираются на предсказуемые порядковые номера; непредсказуемые номера проверяет CertificateNumberTest.
        config(['gifter.number.style' => 'sequence']);
    }

    /** Пакет без базового состава со сроком 12 месяцев — состав в тестах набирается доп. услугами. */
    protected function emptyPackageId(): int
    {
        return Package::firstOrCreate(['name' => 'Тестовый продукт'], ['validity_value' => 12, 'validity_unit' => 'months'])->id;
    }

    /** Бесплатный пакет без базового состава. */
    protected function complimentaryPackageId(): int
    {
        return Package::firstOrCreate(['name' => 'Тестовый бесплатный'], [
            'validity_value' => 12, 'validity_unit' => 'months', 'is_complimentary' => true, 'complimentary_purpose' => 'other',
        ])->id;
    }

    /** Бронь по сертификату на имя получателя: заезд сегодня (или с начала срока), двое суток. */
    protected function reserve(Certificate $certificate, ?string $bookingNumber = null): Certificate
    {
        $certificate->refresh()->load('recipient');
        $arrival = $certificate->valid_from->gt(today()) ? $certificate->valid_from : today();

        return app(ReservationManager::class)->save($certificate, [
            'booking_number' => $bookingNumber ?? 'PMS-'.$certificate->id,
            'guest_name' => trim($certificate->recipient?->last_name.' '.$certificate->recipient?->first_name),
            'arrival' => $arrival, 'departure' => $arrival->copy()->addDays(2),
            'note' => 'тест',
        ]);
    }
}
