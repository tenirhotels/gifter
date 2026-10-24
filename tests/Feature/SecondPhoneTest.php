<?php

namespace Tests\Feature;

use App\Exceptions\DomainRuleViolation;
use App\Models\Customer;
use App\Models\Recipient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Второй телефон у заказчика и получателя, зарубежные номера: шифрование, поиск, проверка формата. */
class SecondPhoneTest extends TestCase
{
    use RefreshDatabase;

    public function test_second_phone_is_encrypted_normalized_and_searchable_by_either_number(): void
    {
        $customer = Customer::create(['type' => 'individual', 'last_name' => 'Тестов', 'first_name' => 'Ерлан', 'phone' => '8 701 111 22 33', 'phone2' => '+998 90 123 45 67']);

        $this->assertSame('+77011112233', $customer->fresh()->phone);
        $this->assertSame('+998901234567', $customer->fresh()->phone2);
        $raw = DB::table('customers')->where('id', $customer->id)->first();
        $this->assertStringNotContainsString('998901234567', (string) $raw->phone2, 'В базе номер зашифрован');
        $this->assertSame($customer->id, Customer::query()->whereContact('+7 701 111 22 33')->value('id'));
        $this->assertSame($customer->id, Customer::query()->whereContact('+998 90 123 45 67')->value('id'));
        $this->assertNull(Customer::query()->whereContact('+998 90 000 00 00')->value('id'));
    }

    public function test_same_second_number_is_dropped_and_bad_one_is_refused(): void
    {
        $recipient = Recipient::create(['first_name' => 'Гость', 'phone' => '+77011112233', 'phone2' => '8 701 111 22 33']);
        $this->assertNull($recipient->fresh()->phone2);

        $this->expectException(DomainRuleViolation::class);
        Recipient::create(['first_name' => 'Гость', 'phone' => '+77011112233', 'phone2' => '12345']);
    }

    public function test_foreign_numbers_are_accepted(): void
    {
        foreach (['+998901234567', '+375291234567', '+442071234567'] as $phone) {
            $customer = Customer::create(['type' => 'individual', 'first_name' => 'Тест', 'phone' => $phone]);
            $this->assertSame($phone, $customer->fresh()->phone);
            $this->assertSame($customer->id, Customer::query()->whereContact($phone)->value('id'));
        }
    }
}
