<?php

namespace App\Http\Middleware;

use App\Models\ShopSetting;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Заголовки безопасности для всех ответов: страницы не встраиваются в чужие сайты,
 * браузер не угадывает тип файла, адрес страницы не уходит на сторонние сайты,
 * камера и геолокация закрыты; на HTTPS — только HTTPS (HSTS).
 */
class SecurityHeaders
{
    private const BASE = "frame-ancestors 'none'; base-uri 'self'; form-action 'self'; object-src 'none'";

    /** Хосты аналитики: добавляются только на публичных страницах и только если счётчик задан в «Настройках сайта». */
    private const GA4 = ['https://www.googletagmanager.com', 'https://*.google-analytics.com', 'https://*.analytics.google.com', 'https://*.googletagmanager.com'];

    private const METRIKA = ['https://mc.yandex.ru', 'https://mc.yandex.com', 'https://yastatic.net'];

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'same-origin');
        // Базовые директивы: не встраивать, не менять base, формы только на свой адрес. Ограничение источников — по режиму CSP_MODE.
        $response->headers->set('Content-Security-Policy', self::BASE);
        $this->sources($request, $response);
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=()');
        $response->headers->remove('X-Powered-By');
        if ($request->isSecure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000');
        }

        return $response;
    }

    /**
     * Источники ресурсов: только свой сайт (+ счётчики аналитики на витрине). Filament/Livewire/Alpine требуют 'unsafe-inline' и
     * 'unsafe-eval', поэтому политика не защищает от внедрённого inline-скрипта, но закрывает чужие источники скриптов,
     * подключений и картинок (вынос данных наружу). `report` — отчёты в /csp-report без блокировки.
     */
    private function sources(Request $request, Response $response): void
    {
        $mode = (string) config('gifter.security.csp_mode', 'report');
        if (! in_array($mode, ['report', 'enforce'], true)) {
            return;
        }
        $extra = ['script' => [], 'connect' => [], 'img' => []];
        if (! $request->is('admin', 'admin/*', 'livewire*', 'csp-report')) {
            $shop = ShopSetting::current();
            if ($shop->ga4()) {
                $extra = ['script' => self::GA4, 'connect' => self::GA4, 'img' => self::GA4];
            }
            if ($shop->metrika()) {
                $extra = ['script' => [...$extra['script'], ...self::METRIKA], 'connect' => [...$extra['connect'], ...self::METRIKA, 'wss://mc.yandex.ru'], 'img' => [...$extra['img'], ...self::METRIKA]];
            }
        }
        $policy = implode('; ', [
            self::BASE,
            "default-src 'self'",
            implode(' ', ["script-src 'self' 'unsafe-inline' 'unsafe-eval'", ...$extra['script']]),
            "style-src 'self' 'unsafe-inline'",
            implode(' ', ["img-src 'self' data: blob:", ...$extra['img']]),
            "font-src 'self' data:",
            implode(' ', ["connect-src 'self'", ...$extra['connect']]),
            'report-uri /csp-report',
        ]);
        $response->headers->set($mode === 'enforce' ? 'Content-Security-Policy' : 'Content-Security-Policy-Report-Only', $policy);
    }
}
