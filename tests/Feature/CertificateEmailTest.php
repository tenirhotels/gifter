<?php

namespace Tests\Feature;

use App\Enums\HistoryEvent;
use App\Enums\PaymentMethod;
use App\Enums\Role;
use App\Filament\Resources\Certificates\Pages\ViewCertificate;
use App\Mail\CertificateIssuedMail;
use App\Models\Certificate;
use App\Models\Customer;
use App\Models\OnlineOrder;
use App\Models\Package;
use App\Models\Recipient;
use App\Models\Service;
use App\Models\User;
use App\Services\CertificateDocument;
use App\Services\CertificateMailer;
use App\Services\CertificateManager;
use App\Services\OnlineOrderManager;
use App\Services\PaymentManager;
use App\Services\ServicePricing;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

/** Электронный сертификат: поздравление в PDF, письмо с PDF гостю — само для заявок «на email» и кнопкой в карточке. */
class CertificateEmailTest extends TestCase
{
    use RefreshDatabase;

    private Package $package;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-05-15 12:00:00');
        $this->actingAs(User::factory()->create(['roles' => [Role::SuperAdmin]]));
        $service = Service::factory()->create(['name' => 'Ужин на двоих']);
        app(ServicePricing::class)->addPrice($service, '50000', Carbon::parse('2026-01-01'));
        $this->package = Package::create(['name' => 'Романтический вечер', 'validity_value' => 3, 'validity_unit' => 'months', 'sold_online' => true]);
        $this->package->items()->create(['service_id' => $service->id, 'quantity' => 1]);
    }

    private function issue(Certificate $certificate): Certificate
    {
        $payments = app(PaymentManager::class);
        $payments->markPaid($payments->invoice($certificate, PaymentMethod::KaspiInvoice));

        return app(CertificateManager::class)->issue($certificate)->refresh();
    }

    public function test_website_order_with_email_delivery_gets_pdf_automatically_in_order_language(): void
    {
        Mail::fake();
        $order = OnlineOrder::create(['package_id' => $this->package->id, 'last_name' => 'Иванов', 'first_name' => 'Иван', 'phone' => '+77011234567',
            'email' => 'guest@example.com', 'delivery_method' => 'pdf', 'locale' => 'kk', 'gift_message' => 'Туған күніңмен!']);
        $certificate = $this->issue(app(OnlineOrderManager::class)->process($order, User::factory()->create(['roles' => [Role::Sales]])));

        Mail::assertQueued(CertificateIssuedMail::class, function (CertificateIssuedMail $mail) use ($certificate) {
            return $mail->hasTo('guest@example.com') && $mail->locale === 'kk' && $mail->certificateId === $certificate->id
                && $mail->attachments()[0]->as === 'certificate-'.$certificate->number.'.pdf'
                && str_contains($mail->render(), '/v/'.$certificate->verification_token);
        });
        $entry = $certificate->history()->where('event', HistoryEvent::Emailed)->firstOrFail();
        $this->assertStringNotContainsString('guest@example.com', $entry->description);
        $this->assertSame('Туған күніңмен!', $certificate->gift_message);
    }

    public function test_pickup_orders_and_staff_sales_are_not_mailed_automatically_but_can_be_sent_from_card(): void
    {
        Mail::fake();
        $certificate = $this->issue(app(CertificateManager::class)->createDraft(['package_id' => $this->package->id,
            'customer_id' => Customer::factory()->create(['email' => 'buyer@example.com'])->id]));
        Mail::assertNothingOutgoing();

        Livewire::test(ViewCertificate::class, ['record' => $certificate->id])
            ->assertActionVisible('emailPdf')
            ->mountAction('emailPdf')->assertSchemaStateSet(['email' => 'buyer@example.com'], 'mountedActionSchema0')
            ->setActionData(['email' => 'not-an-email', 'lang' => 'ru'])->callMountedAction()->assertHasActionErrors(['email']);

        Livewire::test(ViewCertificate::class, ['record' => $certificate->id])
            ->callAction('emailPdf', ['email' => 'friend@example.com', 'lang' => 'en'])->assertHasNoActionErrors();
        Mail::assertQueued(CertificateIssuedMail::class, fn (CertificateIssuedMail $mail) => $mail->hasTo('friend@example.com') && $mail->locale === 'en');
        $this->assertTrue($certificate->history()->where('event', HistoryEvent::Emailed)->exists());

        $draft = app(CertificateManager::class)->createDraft(['package_id' => $this->package->id, 'customer_id' => Customer::factory()->create()->id]);
        Livewire::test(ViewCertificate::class, ['record' => $draft->id])->assertActionHidden('emailPdf');
    }

    public function test_pdf_shows_brand_logo_and_gift_message_which_is_encrypted_at_rest(): void
    {
        $certificate = $this->issue(app(CertificateManager::class)->createDraft(['package_id' => $this->package->id,
            'customer_id' => Customer::factory()->create()->id, 'recipient_id' => Recipient::create(['last_name' => 'Смирнова', 'first_name' => 'Анна'])->id,
            'gift_message' => 'С юбилеем, дорогая!']));
        $this->assertNotSame('С юбилеем, дорогая!', $certificate->getRawOriginal('gift_message'));

        $documents = app(CertificateDocument::class);
        $html = view('certificates.pdf', ['pages' => [['certificate' => $certificate, 'qr' => $documents->qrDataUri('x'), 'verifyUrl' => 'x']],
            'hotel' => config('gifter.certificate'), 'logo' => $documents->logoDataUri()])->render();
        $this->assertStringContainsString('С юбилеем, дорогая!', $html);
        $this->assertStringContainsString('data:image/svg+xml;base64,', $html);
        $this->assertStringStartsWith('%PDF', $documents->pdf($certificate));
    }

    public function test_guest_mails_go_through_queue_with_retries_and_queue_is_worked_by_scheduler(): void
    {
        $mail = new CertificateIssuedMail(1, 'kk');
        $this->assertInstanceOf(ShouldQueueAfterCommit::class, $mail);
        $this->assertSame(3, $mail->tries);
        $this->assertSame([60, 300], $mail->backoff);

        $events = collect(app(Schedule::class)->events())->map(fn ($event) => $event->command)->implode("\n");
        $this->assertStringContainsString('queue:work --stop-when-empty --tries=3 --max-time=50', $events);
        $this->assertStringContainsString('gifter:remind-online-orders', $events);
        $this->assertStringContainsString('gifter:remind-expiring', $events);
    }

    public function test_queued_mail_is_really_delivered_with_pdf_attachment(): void
    {
        $certificate = $this->issue(app(CertificateManager::class)->createDraft(['package_id' => $this->package->id, 'customer_id' => Customer::factory()->create()->id]));
        app(CertificateMailer::class)->send($certificate, 'guest@example.com', 'en');

        $messages = app('mailer')->getSymfonyTransport()->messages();
        $this->assertCount(1, $messages);
        $email = $messages[0]->getOriginalMessage();
        $this->assertSame('certificate-'.$certificate->number.'.pdf', $email->getAttachments()[0]->getFilename());
        $this->assertStringContainsString('gift certificate', $email->getSubject());
    }
}
