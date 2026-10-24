<?php

namespace Tests\Feature;

use App\Models\Payment;
use App\Services\Epay\EpayClient;
use App\Services\Epay\EpayException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Диагностика оплаты картой: подсказка при отказе банка по shopId и команда проверки (банк подменён). */
class EpayCheckTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['gifter.epay.client_id' => 'fictional-client', 'gifter.epay.client_secret' => 'fictional-secret', 'gifter.epay.terminal' => 'FICTION-1234', 'gifter.epay.shop_id' => null, 'gifter.epay.env' => 'test']);
    }

    public function test_command_reports_masked_ids_without_secrets(): void
    {
        Http::fake(['*' => Http::response(['access_token' => 'fictional-token'])]);

        $this->artisan('gifter:epay-check')->expectsOutputToContain('…1234')->expectsOutputToContain('Токен получен')->assertSuccessful();
    }

    public function test_command_fails_clearly_when_not_configured(): void
    {
        config(['gifter.epay.client_secret' => null]);

        $this->artisan('gifter:epay-check')->assertFailed();
    }

    public function test_shop_id_rejection_gets_a_hint(): void
    {
        Http::fake(['*oauth2/token' => Http::response(['access_token' => 'fictional-token']), '*' => Http::response(['message' => 'Ошибка при запросе терминала по shopId'], 400)]);
        $payment = new Payment(['amount' => '1000', 'currency' => 'KZT', 'epay_invoice_id' => '1234567']);

        try {
            app(EpayClient::class)->createInvoiceLink($payment, 'Тест', 'a@example.com', '+77010000000', 'acc', 'ru');
            $this->fail('Ожидалась ошибка банка');
        } catch (EpayException $e) {
            $this->assertStringContainsString('EPAY_SHOP_ID', $e->getMessage());
            $this->assertStringContainsString('(400)', $e->getMessage());
        }
    }
}
