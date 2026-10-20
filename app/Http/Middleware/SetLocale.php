<?php

namespace App\Http\Middleware;

use App\Support\LocaleResolver;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;

/**
 * Язык интерфейса — см. LocaleResolver. Явный выбор (?lang или переключатель) запоминается в сессии и в cookie на год,
 * чтобы не терялся при переходах и новых визитах. Не переведённое показывается по-русски.
 */
class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $locale = LocaleResolver::resolve($request);
        app()->setLocale($locale);
        Carbon::setLocale($locale);

        if (LocaleResolver::explicit($request) !== null && $request->hasSession()) {
            $request->session()->put('locale', $locale);
        }

        $response = $next($request);

        if (LocaleResolver::explicit($request) !== null) {
            $response->headers->setCookie(Cookie::create(LocaleResolver::COOKIE, $locale, now()->addYear(), '/', null, $request->isSecure(), false, false, Cookie::SAMESITE_LAX));
        }

        return $response;
    }
}
