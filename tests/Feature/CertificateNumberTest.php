<?php

namespace Tests\Feature;

use App\Models\Certificate;
use App\Models\Customer;
use App\Services\CertificateManager;
use App\Support\CertificateNumber;
use App\Support\NumberWords;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Номера сертификатов: два казахских слова и четыре цифры, без порядка выдачи. */
class CertificateNumberTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['gifter.number.style' => 'words']);
    }

    public function test_word_list_is_clean(): void
    {
        $this->assertSame(NumberWords::LIST, array_values(array_unique(NumberWords::LIST)), 'Без повторов');
        foreach (NumberWords::LIST as $word) {
            $this->assertMatchesRegularExpression('/^[A-Z]{3}$/', $word);
        }
        $this->assertLessThanOrEqual(100, count(NumberWords::LIST));
    }

    public function test_random_numbers_have_the_format_and_do_not_follow_an_order(): void
    {
        $numbers = [];
        for ($i = 0; $i < 300; $i++) {
            $number = CertificateNumber::random();
            $this->assertMatchesRegularExpression('/^[A-Z]{3}-[A-Z]{3}-\d{4}$/', $number);
            $this->assertTrue(CertificateNumber::isValid($number));
            $numbers[] = $number;
        }
        $sorted = $numbers;
        sort($sorted);
        $this->assertNotSame($sorted, $numbers, 'Номера не растут по порядку');
        $this->assertGreaterThan(290, count(array_unique($numbers)), 'Номера почти не повторяются');
    }

    public function test_input_is_normalized_and_typos_in_words_are_caught(): void
    {
        $this->assertSame('TAU-KOL-0482', CertificateNumber::normalize(' tau kol 0482 '));
        $this->assertSame('TAU-KOL-0482', CertificateNumber::normalize('TAU.KOL.0482'));
        $this->assertSame('TAU-KOL-0482', CertificateNumber::normalize('taukol0482'));
        $this->assertNull(CertificateNumber::normalize('TAU-KOL-482'));
        $this->assertNull(CertificateNumber::normalize('GIF-000001'));
        $this->assertTrue(CertificateNumber::isValid('tau kol 0482'));
        $this->assertFalse(CertificateNumber::isValid('TAU-TAU-0482'), 'Слова не повторяются');
        $this->assertFalse(CertificateNumber::isValid('TAU-XYZ-0482'), 'Слова только из списка');
    }

    public function test_new_certificates_get_unique_words_numbers_and_are_found_in_any_case(): void
    {
        $customer = Customer::factory()->create();
        $numbers = [];
        for ($i = 0; $i < 5; $i++) {
            $numbers[] = app(CertificateManager::class)->createDraft(['package_id' => $this->emptyPackageId(), 'customer_id' => $customer->id])->number;
        }

        $this->assertCount(5, array_unique($numbers));
        foreach ($numbers as $number) {
            $this->assertTrue(CertificateNumber::isValid($number));
        }
        $this->assertSame(5, Certificate::query()->count());
        $query = CertificateNumber::searchQuery();
        $found = $query(Certificate::query(), strtolower(str_replace('-', ' ', $numbers[0])))->pluck('number')->all();
        $this->assertContains($numbers[0], $found);
    }

    public function test_sequence_style_is_still_available(): void
    {
        config(['gifter.number.style' => 'sequence']);
        $number = app(CertificateManager::class)->createDraft(['package_id' => $this->emptyPackageId(), 'customer_id' => Customer::factory()->create()->id])->number;

        $this->assertSame('GIF-000001', $number);
    }

    public function test_internal_serial_number_grows_by_one_and_is_independent_of_the_public_number(): void
    {
        $customer = Customer::factory()->create();
        $first = app(CertificateManager::class)->createDraft(['package_id' => $this->emptyPackageId(), 'customer_id' => $customer->id]);
        $second = app(CertificateManager::class)->createDraft(['package_id' => $this->emptyPackageId(), 'customer_id' => $customer->id]);

        $this->assertSame(1, (int) $first->serial_number);
        $this->assertSame(2, (int) $second->serial_number);
        $this->assertDoesNotMatchRegularExpression('/\d{6}/', $first->number, 'Публичный номер не содержит порядкового номера');
    }
}
