<?php

namespace App\Services;

use App\Enums\CertificateKind;
use App\Enums\CertificateStatus;
use App\Enums\ComplimentaryPurpose;
use App\Enums\HistoryEvent;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Exceptions\DomainRuleViolation;
use App\Models\Certificate;
use App\Models\Customer;
use App\Models\Package;
use App\Models\Payment;
use App\Models\Recipient;
use App\Models\User;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use OpenSpout\Common\Entity\Cell\FormulaCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Reader\XLSX\Options as XlsxOptions;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;

/**
 * Перенос исторических сертификатов из таблицы Excel (лист «Database», строка заголовков находится по названиям столбцов).
 * Строка с проблемой не вводится, а попадает в отчёт; повторный запуск пропускает уже введённые строки (`legacy_key`).
 * Всё делается в одной транзакции: «сухой прогон» выполняет тот же код и откатывает его, поэтому отчёт точен.
 * Оплаты — «выполнено и сверено» без комиссий; сертификаты — без позиций состава (прайсов за те годы нет).
 */
class LegacyCertificateImport
{
    public const SHEET = 'Database';

    public const IMPORT_USER_EMAIL = 'import@gifter.invalid';

    /** Названия столбцов таблицы → ключи. */
    private const COLUMNS = [
        '№' => 'number', 'Ночей' => 'nights', 'Срок начала' => 'start', 'Срок окончания' => 'end', 'Дней' => 'days',
        'Стоимость допуслуг' => 'extras', 'Итоговая стоимость' => 'total', 'Пакет' => 'package', 'Способ оплаты' => 'payment',
        'Покупатель' => 'buyer', 'Номер покупателя' => 'buyer_phone', 'Гость' => 'guest', 'Номер гостя' => 'guest_phone',
        'Комментарий' => 'comment', 'Статус' => 'status', 'Номер бронирования' => 'booking', 'Название компании и email' => 'company',
        'Проживание' => 'stay', 'Стоимость проживания' => 'stay', 'Состав пакета' => 'composition', 'Замечания при проверке' => 'remarks',
    ];

    /** Исходные значения, которые не вписались в стандарт и уходят в примечание сертификата (заполняется в prepare()). @var list<string> */
    private array $extra = [];

    public function __construct(private readonly CertificateNumberGenerator $numbers) {}

    /**
     * @return array{rows: list<array{row: int, number: string, result: string, reason: string}>, counts: array<string, int>, summary: array<string, mixed>}
     */
    public function run(string $path, bool $dryRun = false): array
    {
        $rows = $this->read($path);
        $report = [];
        $counts = ['imported' => 0, 'skipped' => 0, 'duplicate' => 0, 'warnings' => 0];
        $summary = ['status' => [], 'kind' => [], 'paid_by_year' => [], 'warnings' => []];
        $seen = [];

        DB::beginTransaction();
        try {
            $user = $this->importUser();
            foreach ($rows as $line => $row) {
                $number = trim((string) ($row['number'] ?? ''));
                try {
                    $warnings = [];
                    $data = $this->prepare($row, $warnings);
                    $key = $this->key($data, $seen);
                    if (Certificate::query()->where('legacy_key', $key)->exists()) {
                        $counts['duplicate']++;
                        $report[] = ['row' => $line, 'number' => $number, 'result' => 'уже перенесён', 'reason' => ''];

                        continue;
                    }
                    DB::transaction(fn () => $this->store($data, $key, $user));
                    $counts['imported']++;
                    $this->tally($summary, $data, $warnings);
                    $counts['warnings'] += $warnings ? 1 : 0;
                    $report[] = ['row' => $line, 'number' => $number, 'result' => $warnings ? 'перенесён с замечанием' : 'перенесён', 'reason' => implode('; ', $warnings)];
                } catch (DomainRuleViolation $e) {
                    $counts['skipped']++;
                    $report[] = ['row' => $line, 'number' => $number, 'result' => 'пропущен', 'reason' => $e->getMessage()];
                } catch (\Throwable $e) {
                    // Данные клиента в сообщение не попадают: только тип ошибки.
                    $counts['skipped']++;
                    $report[] = ['row' => $line, 'number' => $number, 'result' => 'пропущен', 'reason' => 'ошибка записи строки ('.class_basename($e).')'];
                }
            }
            $dryRun ? DB::rollBack() : DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        ksort($summary['paid_by_year']);

        return ['rows' => $report, 'counts' => $counts, 'summary' => $summary];
    }

    /** @return array<int, array<string, mixed>> строки таблицы по номеру строки в файле */
    private function read(string $path): array
    {
        $options = new XlsxOptions;
        $options->SHOULD_PRESERVE_EMPTY_ROWS = true; // номера строк в отчёте совпадают с номерами в Excel
        $reader = new XlsxReader($options);
        $reader->open($path);
        $result = [];
        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                if ($sheet->getName() !== self::SHEET) {
                    continue;
                }
                $columns = [];
                foreach ($sheet->getRowIterator() as $index => $row) {
                    $cells = $this->cellValues($row);
                    if ($columns === [] && in_array('№', array_map(fn ($c) => trim((string) $c), $cells), true) && in_array('Срок начала', array_map(fn ($c) => trim((string) $c), $cells), true)) {
                        foreach ($cells as $position => $title) {
                            $title = trim((string) $title);
                            if (isset(self::COLUMNS[$title])) {
                                $columns[$position] = self::COLUMNS[$title];
                            }
                        }

                        continue;
                    }
                    if ($columns === []) {
                        continue;
                    }
                    $mapped = [];
                    foreach ($columns as $position => $key) {
                        $mapped[$key] = $cells[$position] ?? null;
                    }
                    if (collect($mapped)->contains(fn ($v) => $v !== null && trim((string) ($v instanceof DateTimeInterface ? 'd' : $v)) !== '')) {
                        $result[$index] = $mapped;
                    }
                }
            }
        } finally {
            $reader->close();
        }
        if ($result === []) {
            throw new DomainRuleViolation(__('В файле нет листа «:p1» со строками данных.', ['p1' => self::SHEET]));
        }

        return $result;
    }

    /** Значения ячеек строки; у формул — вычисленное значение, сохранённое в файле (а не текст формулы). @return array<int, mixed> */
    private function cellValues(Row $row): array
    {
        $values = [];
        foreach ($row->getCells() as $position => $cell) {
            $values[$position] = $cell instanceof FormulaCell ? $cell->getComputedValue() : $cell->getValue();
        }

        return $values;
    }

    /**
     * Сводка для сверки с таблицей: по статусам, видам, платным продажам по годам и видам замечаний.
     *
     * @param  array<string, mixed>  $summary
     * @param  array<string, mixed>  $data
     * @param  list<string>  $warnings
     */
    private function tally(array &$summary, array $data, array $warnings): void
    {
        $summary['status'][$data['status']->value] = ($summary['status'][$data['status']->value] ?? 0) + 1;
        $kind = $data['free'] ? 'бесплатный' : 'продажа';
        $summary['kind'][$kind] = ($summary['kind'][$kind] ?? 0) + 1;
        if (! $data['free']) {
            $year = $data['start']->year;
            $row = $summary['paid_by_year'][$year] ?? ['count' => 0, 'sum' => 0.0];
            $summary['paid_by_year'][$year] = ['count' => $row['count'] + 1, 'sum' => $row['sum'] + $data['total']];
        }
        foreach ($warnings as $warning) {
            $summary['warnings'][$warning] = ($summary['warnings'][$warning] ?? 0) + 1;
        }
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  list<string>  $warnings
     * @return array<string, mixed>
     */
    private function prepare(array $row, array &$warnings): array
    {
        $this->extra = [];
        $start = $this->date($row['start'] ?? null);
        $end = $this->date($row['end'] ?? null);
        if (! $start || ! $end) {
            throw new DomainRuleViolation(__('нет даты начала или окончания срока'));
        }
        if ($end->lt($start)) {
            throw new DomainRuleViolation(__('срок окончания раньше срока начала'));
        }

        $payment = $this->paymentKind((string) ($row['payment'] ?? ''), (string) ($row['status'] ?? ''));
        $free = $payment === 'promo';
        $total = $this->amount($row['total'] ?? null);
        if (! $free && ($total === null || $total <= 0)) {
            throw new DomainRuleViolation(__('нет суммы платного сертификата (пусто, «#VALUE!» или 0)'));
        }
        $buyer = $this->person((string) ($row['buyer'] ?? ''), (string) ($row['buyer_phone'] ?? ''), $warnings, 'покупателя');
        if (! $free && $buyer === null) {
            throw new DomainRuleViolation(__('нет покупателя (ни имени, ни телефона)'));
        }

        $status = $this->status((string) ($row['status'] ?? ''), $end);
        $booking = $this->booking((string) ($row['booking'] ?? ''), $warnings);
        [$company, $companyEmail] = $this->company((string) ($row['company'] ?? ''));
        if (filled($row['package'] ?? null)) {
            $this->extra('Тип', (string) $row['package']);
        }
        if ($buyer === null) { // компания и email без покупателя иначе пропали бы
            $this->extra('Компания в таблице', (string) $company);
            $this->extra('Email в таблице', (string) $companyEmail);
        }
        $this->checkConsistency($row, $start, $end, $free, $total, $status, $warnings);
        if (filled($row['remarks'] ?? null)) {
            $this->extra('Замечание', (string) $row['remarks']);
        }

        return [
            'number' => trim((string) ($row['number'] ?? '')), 'start' => $start, 'end' => $end, 'status' => $status,
            'free' => $free, 'payment' => $payment, 'total' => $total ?? 0.0,
            'package' => $this->packageName((string) (array_key_exists('composition', $row) ? ($row['composition'] ?? '') : ($row['package'] ?? ''))), 'package_type' => trim((string) ($row['package'] ?? '')), 'buyer' => $buyer, 'company' => $company, 'company_email' => $companyEmail,
            'guest' => $this->person((string) ($row['guest'] ?? ''), (string) ($row['guest_phone'] ?? ''), $warnings, 'гостя'),
            'booking' => $booking, 'comment' => trim((string) preg_replace('/\s+/u', ' ', (string) ($row['comment'] ?? ''))),
            'stay' => $this->amount($row['stay'] ?? null), 'extras' => $this->amount($row['extras'] ?? null), 'nights' => $row['nights'] ?? null,
            'raw_buyer' => trim((string) ($row['buyer'] ?? '')), 'extra' => $this->extra,
        ];
    }

    /**
     * Проверки согласованности строки: расхождения не мешают переносу, но попадают в замечания отчёта и в примечание.
     *
     * @param  array<string, mixed>  $row
     * @param  list<string>  $warnings
     */
    private function checkConsistency(array $row, Carbon $start, Carbon $end, bool $free, ?float $total, CertificateStatus $status, array &$warnings): void
    {
        $days = $this->amount($row['days'] ?? null);
        $byDates = (int) $start->diffInDays($end);
        if ($days !== null && abs($days - $byDates) > 1) {
            $warnings[] = 'срок в днях не совпадает с датами';
            $this->extra('Дней в таблице', number_format($days, 0, '', '').', по датам: '.$byDates);
        }
        $stay = $this->amount($row['stay'] ?? null);
        if (! $free && $total !== null && $stay !== null) {
            $parts = $stay + ($this->amount($row['extras'] ?? null) ?? 0);
            if (abs($total - $parts) > 1) {
                $warnings[] = 'итоговая сумма не сходится с проживанием и допуслугами';
                $this->extra('Сумма не сходится: итого', number_format($total, 0, '', ' ').', проживание + допуслуги: '.number_format($parts, 0, '', ' '));
            }
        }
        if (! $free && $status === CertificateStatus::Cancelled) {
            $warnings[] = 'аннулирован, платёж записан как оплаченный — проверьте возврат';
            $this->extra('Аннулирован в таблице', 'платёж записан как оплаченный, проверьте возврат');
        }
    }

    /** @param  array<string, mixed>  $data */
    private function store(array $data, string $key, User $user): Certificate
    {
        $kind = $data['free'] ? CertificateKind::Complimentary : CertificateKind::Sale;
        $customer = $data['buyer'] ? $this->customer($data['buyer'], $data['company'], $data['company_email']) : null;
        $recipient = $data['guest'] ? $this->recipient($data['guest']) : null;
        $package = $this->package($data['package']);
        $soldAt = $data['start']->copy();

        $notes = collect([
            $data['number'] !== '' ? 'Прежний № '.$data['number'] : null,
            $data['comment'] !== '' ? 'Комментарий: '.$data['comment'] : null,
            $data['stay'] !== null ? 'Проживание: '.number_format($data['stay'], 0, '', ' ') : null,
            $data['extras'] !== null ? 'Допуслуги: '.number_format($data['extras'], 0, '', ' ') : null,
            $data['nights'] !== null && $data['nights'] !== '' ? 'Ночей: '.$data['nights'] : null,
            ...$data['extra'],
            'Перенесено из таблицы',
        ])->filter()->implode('. ');

        $cancelled = $data['status'] === CertificateStatus::Cancelled;
        $certificate = Certificate::create([
            'number' => $this->numbers->next($soldAt), 'serial_number' => $this->numbers->nextSerial(), 'legacy_number' => $data['number'] !== '' ? Str::limit($data['number'], 60, '') : null, 'legacy_key' => $key,
            'verification_token' => Str::random(32),
            'customer_id' => $customer?->id, 'recipient_id' => $recipient?->id, 'package_id' => $package->id,
            'status' => $data['status'], 'kind' => $kind,
            'complimentary_reason' => $data['free'] ? Str::limit($data['comment'] !== '' ? $data['comment'] : 'Перенесено из таблицы', 1000, '') : null,
            'complimentary_purpose' => $data['free'] ? ($data['payment'] === 'promo' && Str::contains(Str::lower($data['comment']), 'бартер') ? ComplimentaryPurpose::Barter : ComplimentaryPurpose::Partners) : null,
            'complimentary_approved_by' => $data['free'] ? $user->id : null, 'complimentary_approved_at' => $data['free'] ? $soldAt : null,
            'sold_at' => $soldAt, 'valid_from' => $data['start'], 'valid_until' => $data['end'], 'custom_validity' => true,
            'total_amount' => $data['total'], 'payable_amount' => $data['free'] ? 0 : $data['total'], 'currency' => config('gifter.currency', 'KZT'),
            'issued_at' => $soldAt, 'cancelled_at' => $cancelled ? $soldAt : null, 'cancellation_reason' => $cancelled ? 'Аннулирован (из таблицы)' : null,
            'notes' => $notes, 'created_by' => $user->id, 'updated_by' => $user->id, 'source' => 'staff',
            'pms_booking_number' => $data['booking'],
        ]);

        if (! $data['free']) {
            $paidAt = $soldAt->copy()->setTime(12, 0);
            Payment::create([
                'certificate_id' => $certificate->id, 'method' => $this->method($data['payment']), 'status' => PaymentStatus::Paid,
                'amount' => $data['total'], 'currency' => $certificate->currency, 'invoiced_at' => $paidAt, 'paid_at' => $paidAt,
                'reconciled_at' => $paidAt, 'reconciled_by' => $user->id, 'comment' => 'Перенесено из таблицы', 'recorded_by' => $user->id,
            ]);
        }
        $certificate->history()->create([
            'user_id' => $user->id, 'event' => HistoryEvent::Imported,
            'description' => 'Перенос из таблицы'.($data['number'] !== '' ? ', прежний № '.$data['number'] : ''),
            'after' => ['status' => $data['status']->value, 'valid_from' => $data['start']->toDateString(), 'valid_until' => $data['end']->toDateString(), 'total_amount' => (string) $data['total']],
        ]);

        return $certificate;
    }

    /** Ключ строки для повторных запусков: содержимое + порядковый номер одинаковых строк. @param  array<string, int>  $seen */
    private function key(array $data, array &$seen): string
    {
        $base = sha1(implode('|', [$data['number'], $data['start']->toDateString(), $data['end']->toDateString(), $data['raw_buyer'], $data['total'], $data['booking']]));
        $seen[$base] = ($seen[$base] ?? 0) + 1;

        return $base.($seen[$base] > 1 ? '-'.$seen[$base] : '');
    }

    private function date(mixed $value): ?Carbon
    {
        if ($value instanceof DateTimeInterface) {
            return Carbon::instance($value)->startOfDay();
        }
        if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}/', trim($value))) {
            return Carbon::parse(trim($value))->startOfDay();
        }
        if (is_numeric($value) && (float) $value > 20000 && (float) $value < 80000) { // серийный номер даты Excel
            return Carbon::create(1899, 12, 30)->addDays((int) $value)->startOfDay();
        }

        return null;
    }

    private function amount(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }
        if (! is_scalar($value)) {
            return null; // даты и прочее в числовом столбце — не сумма
        }
        $clean = str_replace([' ', "\u{a0}", ','], ['', '', '.'], trim((string) $value));

        return is_numeric($clean) ? (float) $clean : null;
    }

    /** promo | pos | bank | cash. «Промо» бывает и в способе оплаты, и в статусе. */
    private function paymentKind(string $payment, string $status): string
    {
        $payment = Str::lower(trim($payment));

        return match (true) {
            str_contains($payment, 'промо'), str_contains($payment, 'бартер') => 'promo',
            str_contains($payment, 'терминал') => 'pos',
            str_contains($payment, 'банк') => 'bank',
            str_contains($payment, 'налич') => 'cash',
            Str::lower(trim($status)) === 'промо' => 'promo',
            default => throw new DomainRuleViolation(__('не указан способ оплаты')),
        };
    }

    private function method(string $kind): PaymentMethod
    {
        return match ($kind) {
            'pos' => PaymentMethod::PosTerminal,
            'bank' => PaymentMethod::BankTransfer,
            default => PaymentMethod::Cash,
        };
    }

    private function status(string $status, Carbon $end): CertificateStatus
    {
        return match (Str::lower(trim($status))) {
            'использован' => CertificateStatus::Used,
            'аннулирован' => CertificateStatus::Cancelled,
            'просрочен' => CertificateStatus::Expired,
            default => $end->lt(today()) ? CertificateStatus::Expired : CertificateStatus::Active,
        };
    }

    private function packageName(string $text): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));

        return $text !== '' ? Str::limit($text, 200, '') : 'без пакета';
    }

    private function package(string $name): Package
    {
        return Package::query()->firstOrCreate(['name' => 'Архив: '.$name], [
            'validity_value' => 60, 'validity_unit' => 'days', 'is_active' => false, 'sold_online' => false, 'is_complimentary' => false,
        ]);
    }

    /** Запомнить исходное значение для примечания: пробелы схлопываются, длина ограничена. */
    private function extra(string $label, string $value): void
    {
        $value = trim((string) preg_replace('/\s+/u', ' ', $value));
        if ($value !== '') {
            $this->extra[] = $label.': '.Str::limit($value, 200, '…');
        }
    }

    /** Номер брони PMS: очищенный и в формате `ГГГГММДД-код-N`; «бартер» и мусор — без брони. @param  list<string>  $warnings */
    private function booking(string $value, array &$warnings): ?string
    {
        $clean = preg_replace('/[\p{Z}\s\x{200B}-\x{200D}\x{FEFF}]+/u', '', $value);
        if ($clean === '' || $clean === null) {
            return null;
        }
        if (preg_match('/^\d{8}-\d+-\d+$/', $clean)) {
            return $clean;
        }
        $this->extra('Бронь в таблице', $value);
        if (! str_contains(Str::lower($clean), 'бартер')) {
            $warnings[] = 'номер брони не распознан — без брони';
        }

        return null;
    }

    /** @return array{0: ?string, 1: ?string} название компании и email из свободной строки */
    private function company(string $text): array
    {
        $text = trim($text);
        if ($text === '' || $text === '1') {
            return [null, null];
        }
        $email = preg_match('/[\w.+\-]+@[\w\-]+(?:\.[\w\-]+)+/u', $text, $m) ? Str::lower($m[0]) : null;
        $name = trim((string) preg_replace('/\s+/u', ' ', $email ? str_ireplace($m[0], '', $text) : $text), ' ,;.');

        return [$name !== '' ? Str::limit($name, 250, '') : null, $email];
    }

    /**
     * Человек из имени и телефонов. В ячейке может быть до двух номеров (через «,», «;», «/» или перенос строки): первый — основной,
     * второй — дополнительный. Номера приводятся к +…; непригодный номер отбрасывается с замечанием.
     *
     * @param  list<string>  $warnings
     * @return array{last: ?string, first: ?string, middle: ?string, phone: ?string, phone2: ?string}|null
     */
    private function person(string $name, string $phone, array &$warnings, string $who): ?array
    {
        $name = trim((string) preg_replace('/\s+/u', ' ', $name));
        [$phone, $phone2] = $this->phones($phone, $warnings, $who);
        if ($name === '' && $phone === null) {
            return null;
        }
        $parts = $name === '' ? [] : explode(' ', $name);
        [$last, $first, $middle] = match (count($parts)) {
            0 => [null, null, null],
            1 => [null, $parts[0], null],
            2 => [$parts[0], $parts[1], null],
            default => [$parts[0], $parts[1], implode(' ', array_slice($parts, 2))],
        };

        return ['last' => $last, 'first' => $first, 'middle' => $middle, 'phone' => $phone, 'phone2' => $phone2];
    }

    /**
     * @param  list<string>  $warnings
     * @return array{0: ?string, 1: ?string} основной и дополнительный номер
     */
    private function phones(string $raw, array &$warnings, string $who): array
    {
        $good = [];
        $bad = false;
        foreach (preg_split('/[,;\/\r\n]+/u', $raw) ?: [] as $part) {
            if (trim($part) === '') {
                continue;
            }
            $phone = $this->phone($part);
            if ($phone === false) {
                $bad = true;
                $this->extra("Телефон {$who} в таблице", $part);
            } elseif ($phone !== null && ! in_array($phone, $good, true)) {
                $good[] = $phone;
            }
        }
        if ($bad) {
            $warnings[] = "телефон {$who} непригоден — не перенесён";
        }
        if (count($good) > 2) {
            $this->extra("Лишние телефоны {$who} в таблице", implode(', ', array_slice($good, 2)));
            $warnings[] = "у {$who} больше двух телефонов — лишние не перенесены";
        }

        return [$good[0] ?? null, $good[1] ?? null];
    }

    /** Телефон в виде +…: «+» и 8–15 цифр — как есть; 8…/7… (11 цифр) и 10 цифр — казахстанский +7…; 11–15 цифр без «+» — зарубежный; пусто — null; не телефон — false. */
    private function phone(string $raw): string|false|null
    {
        $digits = preg_replace('/\D/', '', $raw);
        if ($digits === '' || $digits === null) {
            return null;
        }
        if (str_starts_with(trim($raw), '+') && strlen($digits) >= 8 && strlen($digits) <= 15) {
            return '+'.$digits;
        }
        if (strlen($digits) === 11 && in_array($digits[0], ['7', '8'], true)) {
            return '+7'.substr($digits, 1);
        }
        if (strlen($digits) === 10) {
            return '+7'.$digits;
        }
        if (strlen($digits) >= 11 && strlen($digits) <= 15 && $digits[0] !== '0' && ! preg_match('/\d{8}-\d+-\d+/', $raw)) {
            return '+'.$digits;
        }

        return false;
    }

    /** @param  array{last: ?string, first: ?string, middle: ?string, phone: ?string, phone2: ?string}  $p */
    private function customer(array $p, ?string $company, ?string $email): Customer
    {
        $existing = $p['phone'] ? Customer::query()->whereContact($p['phone'])->first() : null;
        $existing ??= $p['phone'] ? null : Customer::query()->where('full_name', trim(implode(' ', array_filter([$p['last'], $p['first'], $p['middle']]))))->first();
        if ($existing) {
            return $existing;
        }

        return Customer::create([
            'type' => 'individual', 'last_name' => $p['last'], 'first_name' => $p['first'] ?? ($p['last'] ? null : 'Покупатель (архив)'), 'middle_name' => $p['middle'],
            'phone' => $p['phone'], 'phone2' => $p['phone2'], 'email' => $email, 'company' => $company, 'notes' => $company ? 'Компания: '.$company : null,
        ]);
    }

    /** @param  array{last: ?string, first: ?string, middle: ?string, phone: ?string, phone2: ?string}  $p */
    private function recipient(array $p): Recipient
    {
        $existing = $p['phone'] ? Recipient::query()->whereContact($p['phone'])->first() : null;
        if ($existing) {
            return $existing;
        }

        return Recipient::create(['last_name' => $p['last'], 'first_name' => $p['first'] ?? ($p['last'] ? null : 'Гость (архив)'), 'middle_name' => $p['middle'], 'phone' => $p['phone'], 'phone2' => $p['phone2']]);
    }

    /** Служебный неактивный пользователь: от его имени записываются перенесённые данные. */
    private function importUser(): User
    {
        return User::query()->firstOrCreate(['email' => self::IMPORT_USER_EMAIL], [
            'name' => 'Импорт из таблицы', 'password' => Hash::make(Str::random(48)), 'is_active' => false, 'roles' => [],
        ]);
    }
}
