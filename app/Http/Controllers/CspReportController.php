<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * Отчёты браузера о нарушении CSP (режим CSP_MODE=report). В журнал пишутся только директива и хост заблокированного
 * источника — без адресов страниц с параметрами, без содержимого. Маленькое тело, лимит маршрута.
 */
class CspReportController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $raw = substr($request->getContent(), 0, 4096);
        $data = (array) json_decode($raw, true);
        $report = (array) ($data['csp-report'] ?? ($data[0]['body'] ?? []));
        $blocked = (string) ($report['blocked-uri'] ?? $report['blockedURL'] ?? '');
        $host = parse_url($blocked, PHP_URL_HOST) ?: mb_substr($blocked, 0, 30);
        Log::notice('CSP: нарушение', [
            'директива' => mb_substr((string) ($report['effective-directive'] ?? $report['effectiveDirective'] ?? $report['violated-directive'] ?? ''), 0, 40),
            'источник' => mb_substr((string) $host, 0, 80),
        ]);

        return response()->noContent();
    }
}
