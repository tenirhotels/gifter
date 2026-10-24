<?php

namespace Tests\Feature;

use App\Enums\CertificateKind;
use App\Enums\CertificateStatus;
use App\Enums\HistoryEvent;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Certificate;
use App\Models\CertificateCommission;
use App\Models\Customer;
use App\Models\Package;
use App\Models\Payment;
use App\Models\PmsSetting;
use App\Models\Recipient;
use App\Services\LegacyCertificateImport;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\FormulaCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Tests\TestCase;

/** Перенос исторических сертификатов из таблицы: вымышленные строки, настоящих данных клиентов нет. */
class LegacyImportTest extends TestCase
{
    use RefreshDatabase;

    private string $file;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-23 12:00:00');
        $this->file = sys_get_temp_dir().'/legacy-import-'.uniqid().'.xlsx';
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
        parent::tearDown();
    }

    private function d(string $date): DateTimeImmutable
    {
        return new DateTimeImmutable($date);
    }

    /** @param  list<list<mixed>>  $rows  строки данных в порядке столбцов таблицы (после служебного столбца A) */
    private function workbook(array $rows): string
    {
        $writer = new Writer;
        $writer->openToFile($this->file);
        $writer->getCurrentSheet()->setName('Database');
        $writer->addRow(Row::fromValues([null, 'Не трогать']));
        $writer->addRow(Row::fromValues([]));
        $writer->addRow(Row::fromValues([null, '№', 'Ночей', 'Срок начала', 'Срок окончания', 'Дней', 'Стоимость допуслуг', 'Итоговая стоимость', 'Пакет', 'Способ оплаты',
            'Покупатель', 'Номер покупателя', 'Гость', 'Номер гостя', 'Комментарий', 'Статус', 'Номер бронирования', 'Название компании и email', 'Проживание']));
        foreach ($rows as $row) {
            $writer->addRow(new Row(array_map(fn ($v) => $v instanceof Cell ? $v : Cell::fromValue($v), [null, ...$row])));
        }
        $writer->close();

        return $this->file;
    }

    /** @return list<mixed> */
    private function row(array $override = []): array
    {
        $row = $override + [
            0 => '2021-0001', 1 => '1', 2 => $this->d('2021-05-12'), 3 => $this->d('2021-08-31'), 4 => 111, 5 => null, 6 => 200000, 7 => '1 ночь ',
            8 => 'Пос-терминал', 9 => 'Амина Тестова', 10 => '87017382014', 11 => 'Гость Тестов', 12 => '+7 701 111 22 33', 13 => 'оплата в кассе', 14 => 'Использован',
            15 => "20210512-9305-1234567890\u{a0}", 16 => null, 17 => 160000,
        ];
        ksort($row);

        return array_values($row);
    }

    public function test_sale_is_imported_with_payment_history_and_old_number(): void
    {
        $result = app(LegacyCertificateImport::class)->run($this->workbook([$this->row()]));

        $this->assertSame(1, $result['counts']['imported']);
        $certificate = Certificate::firstOrFail();
        $this->assertSame('2021-0001', $certificate->legacy_number);
        $this->assertStringContainsString('Прежний № 2021-0001', (string) $certificate->notes);
        $this->assertNotSame('2021-0001', $certificate->number, 'Номер — по шаблону системы');
        $this->assertSame(CertificateStatus::Used, $certificate->status);
        $this->assertSame(CertificateKind::Sale, $certificate->kind);
        $this->assertSame('2021-05-12', $certificate->valid_from->toDateString());
        $this->assertSame('2021-08-31', $certificate->valid_until->toDateString());
        $this->assertSame('200000.00', (string) $certificate->total_amount);
        $this->assertSame('20210512-9305-1234567890', $certificate->pms_booking_number, 'Номер брони очищен от пробелов');
        $this->assertSame('Архив: 1 ночь', $certificate->package->name);
        $this->assertFalse($certificate->package->is_active);
        $this->assertFalse($certificate->package->sold_online);

        $payment = Payment::firstOrFail();
        $this->assertSame(PaymentMethod::PosTerminal, $payment->method);
        $this->assertSame(PaymentStatus::Paid, $payment->status);
        $this->assertNotNull($payment->reconciled_at);
        $this->assertSame('200000.00', (string) $payment->amount);
        $this->assertSame(HistoryEvent::Imported, $certificate->history()->firstOrFail()->event);
        $this->assertSame('+77017382014', Customer::firstOrFail()->phone, 'Телефон 8… приведён к +7…');
        $this->assertSame('+77011112233', Recipient::firstOrFail()->phone);
        $this->assertNull(CertificateCommission::query()->first(), 'Комиссии для архива не начисляются');
    }

    public function test_promo_rows_become_complimentary_without_payment(): void
    {
        app(LegacyCertificateImport::class)->run($this->workbook([
            $this->row([0 => '84', 8 => 'Промо / Бартер', 6 => null, 9 => null, 10 => null, 13 => 'бартер Esquire', 14 => null, 15 => 'бартер']),
        ]));

        $certificate = Certificate::firstOrFail();
        $this->assertSame(CertificateKind::Complimentary, $certificate->kind);
        $this->assertSame('0.00', (string) $certificate->payable_amount);
        $this->assertSame(0, Payment::count());
        $this->assertNull($certificate->customer_id);
        $this->assertNull($certificate->pms_booking_number, '«бартер» — не номер брони');
        $this->assertSame(CertificateStatus::Expired, $certificate->status, 'Срок прошёл, статуса нет — истёк');
    }

    public function test_statuses_follow_the_table_or_the_dates(): void
    {
        app(LegacyCertificateImport::class)->run($this->workbook([
            $this->row([0 => 'a1', 14 => 'Аннулирован']),
            $this->row([0 => 'a2', 14 => 'Просрочен', 9 => 'Иван Другой', 10 => '87010000001']),
            $this->row([0 => 'a3', 14 => null, 3 => $this->d('2027-01-10'), 9 => 'Пётр Третий', 10 => '87010000002']),
        ]));

        $this->assertSame(CertificateStatus::Cancelled, Certificate::where('legacy_number', 'a1')->firstOrFail()->status);
        $this->assertSame(CertificateStatus::Expired, Certificate::where('legacy_number', 'a2')->firstOrFail()->status);
        $this->assertSame(CertificateStatus::Active, Certificate::where('legacy_number', 'a3')->firstOrFail()->status);
    }

    public function test_rows_with_problems_go_to_report_and_are_not_imported(): void
    {
        $result = app(LegacyCertificateImport::class)->run($this->workbook([
            $this->row([0 => 'ok1']),
            $this->row([0 => 'no-dates', 2 => null]),
            $this->row([0 => 'bad-sum', 6 => '#VALUE!', 9 => 'Кто-то Ещё', 10 => '87010000003']),
            $this->row([0 => 'no-buyer', 9 => null, 10 => null]),
            $this->row([0 => 'no-pay', 8 => null, 9 => 'Ещё Один', 10 => '87010000004']),
        ]));

        $this->assertSame(1, $result['counts']['imported']);
        $this->assertSame(4, $result['counts']['skipped']);
        $reasons = collect($result['rows'])->where('result', 'пропущен')->pluck('reason', 'number');
        $this->assertStringContainsString('нет даты', $reasons['no-dates']);
        $this->assertStringContainsString('нет суммы', $reasons['bad-sum']);
        $this->assertStringContainsString('нет покупателя', $reasons['no-buyer']);
        $this->assertStringContainsString('способ оплаты', $reasons['no-pay']);
        $this->assertSame(1, Certificate::count());
    }

    public function test_dry_run_writes_nothing_and_second_run_makes_no_duplicates(): void
    {
        $file = $this->workbook([$this->row(), $this->row([0 => '2021-0002', 9 => 'Амина Тестова'])]);

        $dry = app(LegacyCertificateImport::class)->run($file, dryRun: true);
        $this->assertSame(2, $dry['counts']['imported']);
        $this->assertSame(0, Certificate::count());
        $this->assertSame(0, Package::count());
        $this->assertSame(0, Customer::count());

        app(LegacyCertificateImport::class)->run($file);
        $again = app(LegacyCertificateImport::class)->run($file);

        $this->assertSame(2, Certificate::count());
        $this->assertSame(2, $again['counts']['duplicate']);
        $this->assertSame(0, $again['counts']['imported']);
        $this->assertSame(1, Customer::count(), 'Покупатель с тем же телефоном — один');
    }

    public function test_duplicate_old_numbers_and_identical_rows_are_all_imported_under_new_numbers(): void
    {
        $result = app(LegacyCertificateImport::class)->run($this->workbook([$this->row(), $this->row(), $this->row([0 => ''])]));

        $this->assertSame(3, $result['counts']['imported']);
        $this->assertSame(3, Certificate::distinct()->count('number'));
        $this->assertSame(2, Certificate::where('legacy_number', '2021-0001')->count());
    }

    public function test_company_and_email_go_to_customer_and_bad_booking_gets_a_warning(): void
    {
        $result = app(LegacyCertificateImport::class)->run($this->workbook([
            $this->row([15 => '1234', 16 => 'ТОО Ромашка  info@example.test ']),
        ]));

        $customer = Customer::firstOrFail();
        $this->assertSame('ТОО Ромашка', $customer->company);
        $this->assertSame('info@example.test', $customer->email);
        $this->assertNull(Certificate::firstOrFail()->pms_booking_number);
        $this->assertSame('перенесён с замечанием', $result['rows'][0]['result']);
    }

    public function test_command_writes_csv_report_without_contacts(): void
    {
        $file = $this->workbook([$this->row(), $this->row([0 => 'x', 2 => null])]);

        $this->artisan('gifter:import-certificates', ['file' => $file, '--dry-run' => true])->expectsOutputToContain('СУХОЙ ПРОГОН')->assertSuccessful();

        $reports = glob(storage_path('app/imports/report-*-dry.csv'));
        $this->assertNotEmpty($reports);
        $csv = file_get_contents(end($reports));
        $this->assertStringContainsString('нет даты', $csv);
        $this->assertStringNotContainsString('87017382014', $csv);
        $this->assertStringNotContainsString('Тестова', $csv);
        array_map('unlink', $reports);
        $this->assertSame(0, Certificate::count());

        $this->artisan('gifter:import-certificates', ['file' => '/no/such/file.xlsx'])->assertFailed();
    }

    public function test_archive_payment_method_is_not_offered_for_new_payments(): void
    {
        $this->assertArrayNotHasKey(PaymentMethod::PosTerminal->value, PaymentMethod::manualOptions());
    }

    /** Писатель OpenSpout не сохраняет вычисленное значение формул (как делает Excel) — дописываем `<v>` в XML листа. */
    private function withCachedFormulaValues(string $file, array $values): void
    {
        $zip = new \ZipArchive;
        $zip->open($file);
        $sheet = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
        foreach ($values as $formula => $value) {
            $sheet = str_replace("<f>{$formula}</f></c>", "<f>{$formula}</f><v>{$value}</v></c>", $sheet);
        }
        $zip->addFromString('xl/worksheets/sheet1.xml', $sheet);
        $zip->close();
    }

    public function test_formula_cells_are_read_by_their_saved_value(): void
    {
        $file = $this->workbook([$this->row([3 => new FormulaCell('=D4+60', null, null), 6 => new FormulaCell('=G4+H4', null, null)])]);
        $this->withCachedFormulaValues($file, ['D4+60' => 44439, 'G4+H4' => 215000]); // 44439 — 31.08.2021

        $result = app(LegacyCertificateImport::class)->run($file);

        $this->assertSame(1, $result['counts']['imported']);
        $certificate = Certificate::firstOrFail();
        $this->assertSame('2021-08-31', $certificate->valid_until->toDateString());
        $this->assertSame('215000.00', (string) $certificate->total_amount);
    }

    public function test_phone_formats_are_normalized(): void
    {
        app(LegacyCertificateImport::class)->run($this->workbook([
            $this->row([0 => 'p1', 9 => 'Один Тест', 10 => '77010000001']),
            $this->row([0 => 'p2', 9 => 'Два Тест', 10 => '+7 701 000 00 02']),
            $this->row([0 => 'p3', 9 => 'Три Тест', 10 => '7010000003']),
            $this->row([0 => 'p4', 9 => 'Четыре Тест', 10 => '123']),
        ]));

        $this->assertEqualsCanonicalizing(['+77010000001', '+77010000002', '+77010000003'], Customer::whereNotNull('phone_hash')->get()->pluck('phone')->filter()->values()->all());
        $this->assertSame(4, Certificate::count(), 'Непригодный телефон — замечание, сертификат переносится');
    }

    public function test_booking_check_reads_dates_and_marks_unavailable_without_touching_status(): void
    {
        PmsSetting::create(['enabled' => true, 'base_url' => 'https://hub.example.test', 'property_code' => '9305', 'token' => 'fictional-token']);
        app(LegacyCertificateImport::class)->run($this->workbook([
            $this->row([0 => 'b1', 9 => 'Пётр Сидоров', 10 => '87010000011', 11 => 'Анна Иванова', 12 => '87010000012', 15 => '20210512-9305-661']),
            $this->row([0 => 'b2', 9 => 'Кто Тот', 10 => '87010000013', 15 => '20210513-9305-662']),
        ]));
        Http::fake(function ($request) {
            if (str_contains($request->url(), '20210512-9305-661')) {
                return Http::response(['booking' => ['number' => '20210512-9305-661', 'status' => 'Active', 'roomStays' => [[
                    'stayDates' => ['arrivalDateTime' => '2021-05-20T14:00', 'departureDateTime' => '2021-05-21T12:00'], 'guests' => [['firstName' => 'Анна', 'lastName' => 'Иванова']]]],
                    'customer' => ['firstName' => 'Пётр', 'lastName' => 'Сидоров']], 'hub' => ['stale' => false, 'data_age_minutes' => 1]]);
            }

            return Http::response([], 500);
        });

        $this->artisan('gifter:import-check-bookings', ['--pause' => 0])->expectsOutputToContain('Проверено: 2')->assertSuccessful();

        $ok = Certificate::where('legacy_number', 'b1')->firstOrFail();
        $this->assertSame('ok', $ok->pms_check_status);
        $this->assertSame('2021-05-20', $ok->arrival_date->toDateString());
        $this->assertSame('2021-05-21', $ok->departure_date->toDateString());
        $this->assertSame(CertificateStatus::Used, $ok->status, 'Статус сертификата не меняется');
        $this->assertNull($ok->reservation_review_status);
        $this->assertSame('unavailable', Certificate::where('legacy_number', 'b2')->firstOrFail()->pms_check_status);

        $this->artisan('gifter:import-check-bookings', ['--pause' => 0])->expectsOutputToContain('Проверено: 0')->assertSuccessful();
        $this->artisan('gifter:import-check-bookings', ['--pause' => 0, '--retry' => true])->expectsOutputToContain('Проверено: 1')->assertSuccessful();
    }

    public function test_new_table_format_with_composition_and_stay_columns(): void
    {
        $writer = new Writer;
        $writer->openToFile($this->file);
        $writer->getCurrentSheet()->setName('Database');
        $writer->addRow(Row::fromValues([]));
        $writer->addRow(Row::fromValues(['№', 'Ночей', 'Срок начала', 'Срок окончания', 'Дней', 'Пакет', 'Состав пакета', 'Стоимость проживания', 'Стоимость допуслуг', 'Итоговая стоимость',
            'Способ оплаты', 'Покупатель', 'Номер покупателя', 'Гость', 'Номер гостя', 'Комментарий', 'Статус', 'Номер бронирования', 'Название компании и email']));
        $writer->addRow(new Row(array_map(fn ($v) => Cell::fromValue($v), ['2022-0007', 1, $this->d('2022-03-01'), $this->d('2022-06-01'), 92, 'Архивный (Сертификат)', '1 ночь, 2 пеших билета',
            150000, null, 150000, 'Наличные', 'Ерлан Тестов', '87010000000', null, null, 'комментарий', 'Использован', null, null])));
        $writer->close();

        $result = app(LegacyCertificateImport::class)->run($this->file);

        $this->assertSame(1, $result['counts']['imported']);
        $certificate = Certificate::firstOrFail();
        $this->assertSame('Архив: 1 ночь, 2 пеших билета', $certificate->package->name);
        $this->assertStringContainsString('Проживание: 150 000', (string) $certificate->notes);
    }
}
