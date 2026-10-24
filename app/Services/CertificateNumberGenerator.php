<?php

namespace App\Services;

use App\Support\CertificateNumber;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Выдаёт номер сертификата. По умолчанию — непредсказуемый `TAU-482731` (CertificateNumber); режим `sequence` — прежний
 * порядковый шаблон. Счётчик порядкового режима хранится в отдельной таблице
 * и увеличивается под блокировкой строки, поэтому параллельные продажи не получают
 * одинаковых номеров; уникальный индекс на certificates.number — вторая защита.
 */
class CertificateNumberGenerator
{
    /** Внутренний порядковый номер (реестр, отчёты, сортировка): растёт на единицу, пропусков и повторов нет; гостю не показывается. */
    public function nextSerial(): int
    {
        return DB::transaction(function () {
            DB::table('certificate_number_sequences')->insertOrIgnore(['scope' => 'serial', 'last_value' => 0]);
            $next = DB::table('certificate_number_sequences')->where('scope', 'serial')->lockForUpdate()->value('last_value') + 1;
            DB::table('certificate_number_sequences')->where('scope', 'serial')->update(['last_value' => $next]);

            return (int) $next;
        });
    }

    public function next(Carbon $soldAt): string
    {
        if (config('gifter.number.style', 'words') === 'words') {
            return $this->randomNumber();
        }

        $pattern = config('gifter.number.pattern');
        $yearly = str_contains($pattern, '{YYYY}') || str_contains($pattern, '{YY}');
        $scope = $yearly ? (string) $soldAt->year : 'all';

        $value = DB::transaction(function () use ($scope) {
            DB::table('certificate_number_sequences')->insertOrIgnore(['scope' => $scope, 'last_value' => 0]);
            $current = DB::table('certificate_number_sequences')->where('scope', $scope)->lockForUpdate()->value('last_value');
            $next = $current + 1;
            DB::table('certificate_number_sequences')->where('scope', $scope)->update(['last_value' => $next]);

            return $next;
        });

        return strtr($pattern, [
            '{PREFIX}' => config('gifter.number.prefix'),
            '{YYYY}' => $soldAt->format('Y'),
            '{YY}' => $soldAt->format('y'),
            '{SEQ}' => str_pad((string) $value, config('gifter.number.padding'), '0', STR_PAD_LEFT),
        ]);
    }

    /** Непредсказуемый номер `TAU-482731`; при совпадении с существующим — новая попытка. */
    private function randomNumber(): string
    {
        for ($attempt = 0; $attempt < 20; $attempt++) {
            $number = CertificateNumber::random();
            if (! DB::table('certificates')->where('number', $number)->exists()) {
                return $number;
            }
        }

        throw new \RuntimeException('Не удалось подобрать свободный номер сертификата.');
    }
}
