<?php

namespace App\Support;

use App\Mail\ErrorReportMail;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Письмо владельцу о необработанной ошибке (ERROR_MAIL_TO). Одинаковая ошибка — не чаще раза в 15 минут.
 * В письмо не попадают значения запросов, параметры адреса, тексты SQL и данные клиентов.
 */
final class ErrorReporter
{
    public static function report(Throwable $e): void
    {
        $to = config('gifter.monitoring.error_mail_to');
        if (blank($to)) {
            return;
        }
        try {
            $key = 'gifter.error-mail.'.md5(get_class($e).$e->getFile().$e->getLine());
            if (! Cache::add($key, 1, now()->addMinutes(15))) {
                return;
            }
            $message = $e instanceof QueryException ? 'SQLSTATE '.($e->errorInfo[0] ?? '?') : mb_substr((string) $e->getMessage(), 0, 200);
            Mail::to($to)->send(new ErrorReportMail([
                'class' => get_class($e),
                'message' => $message,
                'file' => str_replace(base_path().'/', '', $e->getFile()).':'.$e->getLine(),
                'url' => app()->runningInConsole() ? __('консоль') : request()->method().' /'.ltrim(request()->path(), '/'),
                'time' => now()->format('d.m.Y H:i:s'),
            ]));
        } catch (Throwable) {
            // Сбой отправки не должен порождать новую ошибку.
        }
    }
}
