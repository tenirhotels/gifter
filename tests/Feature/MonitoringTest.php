<?php

namespace Tests\Feature;

use App\Console\Commands\BackupVerify;
use App\Console\Commands\Heartbeat;
use App\Exceptions\DomainRuleViolation;
use App\Mail\ErrorReportMail;
use App\Support\ErrorReporter;
use App\Support\TrustedProxies;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;
use ZipArchive;

/** Мониторинг и устойчивость: письма об ошибках, метка планировщика, проверка бэкапа, доверенные прокси. */
class MonitoringTest extends TestCase
{
    use RefreshDatabase;

    public function test_unhandled_error_is_mailed_once_per_quarter_hour_without_sensitive_data(): void
    {
        Mail::fake();
        config(['gifter.monitoring.error_mail_to' => 'owner@example.test']);

        $error = new RuntimeException('Сбой с данными клиента +77011234567');
        ErrorReporter::report($error);
        ErrorReporter::report($error);

        Mail::assertSent(ErrorReportMail::class, 1);
        Mail::assertSent(ErrorReportMail::class, fn (ErrorReportMail $m) => $m->hasTo('owner@example.test') && $m->info['class'] === RuntimeException::class);

        Mail::fake();
        $query = new QueryException('sqlite', 'select * from customers where phone = ?', ['+77011234567'], new RuntimeException('x'));
        ErrorReporter::report($query);
        Mail::assertSent(ErrorReportMail::class, fn (ErrorReportMail $m) => ! str_contains(json_encode($m->info), '77011234567'));
    }

    public function test_no_error_mail_without_address(): void
    {
        Mail::fake();
        config(['gifter.monitoring.error_mail_to' => null]);

        ErrorReporter::report(new RuntimeException('x'));

        Mail::assertNothingSent();
    }

    public function test_business_rule_violation_is_a_message_not_a_server_error(): void
    {
        $this->app['router']->get('/_test-rule', fn () => throw new DomainRuleViolation('Нельзя: правило'));

        $this->get('/_test-rule')->assertStatus(422)->assertSee('Нельзя: правило');
    }

    public function test_heartbeat_is_written_by_command(): void
    {
        $this->artisan('gifter:heartbeat')->assertSuccessful();

        $this->assertGreaterThan(now()->subMinute()->timestamp, Cache::get(Heartbeat::KEY));
    }

    public function test_trusted_proxies_setting(): void
    {
        $this->assertSame('*', TrustedProxies::resolve(null));
        $this->assertContains('172.64.0.0/13', TrustedProxies::resolve('cloudflare'));
        $this->assertContains('127.0.0.0/8', TrustedProxies::resolve('cloudflare'));
        $this->assertSame(['10.1.2.3', '10.1.2.4'], TrustedProxies::resolve('10.1.2.3, 10.1.2.4'));
        $this->assertFalse(TrustedProxies::isRestricted(''));
        $this->assertTrue(TrustedProxies::isRestricted('cloudflare'));
    }

    public function test_backup_verify_opens_the_archive_with_password(): void
    {
        Storage::fake('backups');
        config(['backup.backup.password' => 'fictional-archive-pass']);
        $this->artisan('gifter:backup-verify')->assertFailed();

        $tmp = storage_path('framework/testing/backup-'.uniqid().'.zip');
        File::ensureDirectoryExists(dirname($tmp));
        $zip = new ZipArchive;
        $zip->open($tmp, ZipArchive::CREATE);
        $zip->addFromString('db-dumps/sqlite-gifter.sql', str_repeat('-- dump', 30)."\nCREATE TABLE customers (id integer);\n");
        $zip->setEncryptionName('db-dumps/sqlite-gifter.sql', ZipArchive::EM_AES_256, 'fictional-archive-pass');
        $zip->close();
        Storage::disk('backups')->put('gifter/gifter-2026-10-19.zip', file_get_contents($tmp));
        File::delete($tmp);

        $this->artisan('gifter:backup-verify')->assertSuccessful();
        $this->assertTrue(Cache::get(BackupVerify::KEY)['ok']);

        config(['backup.backup.password' => 'wrong-password-for-test']);
        $this->artisan('gifter:backup-verify')->assertFailed();
        $this->assertFalse(Cache::get(BackupVerify::KEY)['ok']);
    }
}
