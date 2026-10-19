<?php

namespace Tests\Feature;

use App\Enums\CertificateStatus;
use App\Enums\HistoryEvent;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\Role;
use App\Filament\Resources\Certificates\Pages\CreateCertificate;
use App\Filament\Resources\Certificates\Pages\ViewCertificate;
use App\Models\Certificate;
use App\Models\Customer;
use App\Models\Package;
use App\Models\Recipient;
use App\Models\Service;
use App\Models\User;
use App\Services\AdminCorrections;
use App\Services\CertificateDocument;
use App\Services\CertificateManager;
use App\Services\PaymentManager;
use App\Services\ServicePricing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/** Фаза 4: мастер продажи, PDF и проверка по QR. */
class CertificateSalesTest extends TestCase
{
    use RefreshDatabase;

    private Package $weekend;

    private Service $room;

    private Service $spa;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-18 12:00:00');
        $this->actingAs(User::factory()->create(['roles' => [Role::SuperAdmin]]));
        $pricing = app(ServicePricing::class);
        $this->room = Service::factory()->create(['name' => 'Проживание', 'unit' => 'ночь']);
        $pricing->addPrice($this->room, '150000', Carbon::parse('2026-01-01'));
        $this->spa = Service::factory()->create(['name' => 'SPA', 'unit' => 'сеанс']);
        $pricing->addPrice($this->spa, '40000', Carbon::parse('2026-01-01'));
        $this->weekend = Package::create(['name' => 'Уикенд', 'validity_value' => 2, 'validity_unit' => 'months']);
        $this->weekend->items()->create(['service_id' => $this->room->id, 'quantity' => 2]);
    }

    private function issued(): Certificate
    {
        $certificate = app(CertificateManager::class)->createDraft([
            'package_id' => $this->weekend->id,
            'customer_id' => Customer::factory()->create()->id,
            'recipient_id' => Recipient::create(['last_name' => 'Смирнова', 'first_name' => 'Анна'])->id,
        ]);
        app(PaymentManager::class)->markPaid(app(PaymentManager::class)->invoice($certificate, PaymentMethod::KaspiInvoice));
        app(CertificateManager::class)->issue($certificate);

        return $certificate->refresh();
    }

    public function test_wizard_creates_draft_with_extras_and_invoice_in_one_go(): void
    {
        $this->actingAs(User::factory()->create(['roles' => [Role::Sales]]));
        $customer = Customer::factory()->create();

        Livewire::test(CreateCertificate::class)
            ->fillForm([
                'package_id' => $this->weekend->id,
                'customer_id' => $customer->id,
                'extras' => [['service_id' => $this->spa->id, 'quantity' => 2]],
                'create_invoice' => true,
                'payment_method' => 'kaspi_invoice',
                'invoice_number' => 'K-77',
            ])
            ->assertSee('380 000 ₸')
            ->call('create')
            ->assertHasNoFormErrors();

        $certificate = Certificate::firstOrFail();
        $this->assertSame('380000.00', (string) $certificate->total_amount);
        $this->assertSame(2, $certificate->items()->count());
        $payment = $certificate->payments()->firstOrFail();
        $this->assertSame(PaymentStatus::Pending, $payment->status);
        $this->assertSame('380000.00', (string) $payment->amount);
        $this->assertSame('K-77', $payment->invoice_number);
    }

    public function test_quote_flags_services_without_price(): void
    {
        $noPrice = Service::factory()->create(['name' => 'Без цены']);
        $quote = app(CertificateManager::class)->quote($this->weekend, [['service_id' => $noPrice->id, 'quantity' => 1]], today());

        $this->assertSame('300000.00', $quote['total']);
        $this->assertSame(['Без цены'], $quote['missing']);
    }

    public function test_wizard_rolls_back_everything_on_error(): void
    {
        $noPrice = Service::factory()->create(['name' => 'Без цены']);

        Livewire::test(CreateCertificate::class)
            ->fillForm([
                'package_id' => $this->weekend->id,
                'customer_id' => Customer::factory()->create()->id,
                'extras' => [['service_id' => $noPrice->id, 'quantity' => 1]],
                'create_invoice' => false,
            ])
            ->call('create')
            ->assertNotified();

        $this->assertSame(0, Certificate::count());
    }

    public function test_issue_creates_verification_token_and_pdf(): void
    {
        $certificate = $this->issued();

        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{32}$/', $certificate->verification_token);
        $pdf = app(CertificateDocument::class)->pdf($certificate);
        $this->assertStringStartsWith('%PDF', $pdf);
        $this->assertGreaterThan(5000, strlen($pdf));
        // QR — SVG: не требует расширения gd.
        $this->assertStringStartsWith('data:image/svg+xml;base64,', app(CertificateDocument::class)->qrDataUri('https://example.com'));
    }

    public function test_pdf_download_is_recorded_in_history(): void
    {
        $certificate = $this->issued();

        Livewire::test(ViewCertificate::class, ['record' => $certificate->id])
            ->callAction('downloadPdf')
            ->assertFileDownloaded("certificate-{$certificate->number}.pdf");

        $this->assertSame(HistoryEvent::DocumentDownloaded, $certificate->history()->first()->event);
    }

    public function test_pdf_is_not_available_for_draft_or_cancelled(): void
    {
        $draft = app(CertificateManager::class)->createDraft(['package_id' => $this->weekend->id, 'customer_id' => Customer::factory()->create()->id]);
        Livewire::test(ViewCertificate::class, ['record' => $draft->id])->assertActionHidden('downloadPdf');

        $certificate = $this->issued();
        app(AdminCorrections::class)->setStatus($certificate, CertificateStatus::Cancelled, 'тест');
        Livewire::test(ViewCertificate::class, ['record' => $certificate->id])->assertActionHidden('downloadPdf');
    }

    public function test_public_verification_shows_status_without_personal_data(): void
    {
        $certificate = $this->issued();

        $this->get("/v/{$certificate->verification_token}")->assertOk()
            ->assertSee($certificate->number)->assertSee('Действителен')->assertSee('18.12.2026')
            ->assertDontSee('Смирнова')->assertDontSee('300 000');

        $this->reserve($certificate);
        app(CertificateManager::class)->transition($certificate, CertificateStatus::InUse);
        app(CertificateManager::class)->transition($certificate, CertificateStatus::Used);
        $this->get("/v/{$certificate->verification_token}")->assertOk()->assertSee('Использован');
    }

    public function test_verification_of_expired_by_date_and_unknown_token(): void
    {
        $certificate = $this->issued();
        Carbon::setTestNow('2027-01-10 12:00:00');

        $this->get("/v/{$certificate->verification_token}")->assertOk()->assertSee('Срок действия истёк');
        $this->get('/v/'.str_repeat('a', 32))->assertNotFound()->assertSee('не найден');
        $this->get('/v/short')->assertNotFound();
    }

    public function test_verification_is_rate_limited(): void
    {
        foreach (range(1, 30) as $_) {
            $this->get('/v/'.str_repeat('b', 32));
        }
        $this->get('/v/'.str_repeat('b', 32))->assertStatus(429);
    }
}
