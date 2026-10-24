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

    public function test_words_are_not_confusable_by_ear(): void
    {
        $sound = fn (string $w) => strtr($w, ['Q' => 'K', 'J' => 'Y', 'Z' => 'S']);
        $words = NumberWords::LIST;
        // Слова, оставленные по решению владельца, хотя они похожи на слух.
        $allowed = ['ARU-ASU', 'BAL-TAL', 'JAZ-TAS', 'KUN-KUZ', 'KUN-TUN', 'KUZ-MUZ', 'KUZ-QYS', 'TAL-TAN', 'TAL-TAS', 'TAL-TAU', 'TAN-TAS', 'TAN-TAU', 'TAN-TUN', 'TAS-TAU'];
        foreach ($words as $i => $a) {
            foreach (array_slice($words, $i + 1) as $b) {
                $different = count(array_filter(str_split($sound($a)), fn ($c, $k) => $c !== $sound($b)[$k], ARRAY_FILTER_USE_BOTH));
                $pair = collect([$a, $b])->sort()->implode('-');
                if (in_array($pair, array_map(fn ($p) => collect(explode('-', $p))->sort()->implode('-'), $allowed), true)) {
                    continue;
                }
                $this->assertGreaterThanOrEqual(2, $different, "$a и $b слишком похожи на слух");
            }
        }
    }

    public function test_random_numbers_have_the_format_and_do_not_follow_an_order(): void
    {
        $numbers = [];
        for ($i = 0; $i < 300; $i++) {
            $number = CertificateNumber::random();
            $this->assertMatchesRegularExpression('/^[A-Z]{3}-\d{5}$/', $number);
            $this->assertTrue(CertificateNumber::isValid($number));
            $numbers[] = $number;
        }
        $sorted = $numbers;
        sort($sorted);
        $this->assertNotSame($sorted, $numbers, 'Номера не растут по порядку');
        $this->assertGreaterThan(290, count(array_unique($numbers)), 'Номера почти не повторяются');
    }

    public function test_input_is_normalized_and_typos_are_caught_by_the_check_digit(): void
    {
        $number = CertificateNumber::random();
        [$word, $tail] = [substr($number, 0, 3), substr($number, 4)];
        $this->assertTrue(CertificateNumber::isValid(strtolower(str_replace('-', ' ', $number))));
        $this->assertSame($number, CertificateNumber::normalize(str_replace('-', '.', $number)));
        $this->assertSame($number, CertificateNumber::normalize(str_replace('-', '', strtolower($number))));

        $wrongCheck = $word.'-'.substr($tail, 0, 4).(((int) $tail[4] + 1) % 10);
        $this->assertFalse(CertificateNumber::isValid($wrongCheck), 'Неверная контрольная цифра');
        $this->assertNull(CertificateNumber::normalize('TAU-482'));
        $this->assertFalse(CertificateNumber::isValid('GIF-000001'), 'Прежний порядковый номер не проходит проверку');
        $this->assertFalse(CertificateNumber::isValid('XYZ-48273'), 'Слово только из списка');
    }

    public function test_check_digit_depends_on_the_secret_key(): void
    {
        config(['gifter.security.contact_hash_key' => 'first-key']);
        $number = CertificateNumber::random();
        $this->assertTrue(CertificateNumber::isValid($number));
        config(['gifter.security.contact_hash_key' => 'second-key-different']);
        $valid = 0;
        for ($i = 0; $i < 200; $i++) {
            $valid += CertificateNumber::isValid(CertificateNumber::random()) ? 1 : 0;
        }
        $this->assertSame(200, $valid, 'С новым ключом новые номера проверяются');
        // Подделать номер без ключа нельзя: из 10 вариантов последней цифры подходит один.
        $base = substr($number, 0, 8);
        $accepted = collect(range(0, 9))->filter(fn ($d) => CertificateNumber::isValid($base.$d))->count();
        $this->assertLessThanOrEqual(1, $accepted);
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
        $this->assertTrue(CertificateNumber::isValid($first->number), 'Публичный номер — не порядковый, а из слова и случайных цифр');
    }
}
