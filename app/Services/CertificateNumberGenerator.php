<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Выдаёт следующий номер сертификата. Счётчик хранится в отдельной таблице
 * и увеличивается под блокировкой строки, поэтому параллельные продажи не получают
 * одинаковых номеров; уникальный индекс на certificates.number — вторая защита.
 */
class CertificateNumberGenerator
{
    public function next(Carbon $soldAt): string
    {
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
}
