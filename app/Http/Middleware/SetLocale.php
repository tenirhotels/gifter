<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response;

/**
 * Язык интерфейса: сохранённый у пользователя, иначе выбранный в сессии (экран входа), иначе русский.
 * Доступные языки — config('gifter.locales'); не переведённое показывается по-русски.
 */
class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $available = array_keys(config('gifter.locales'));
        $locale = $request->user()?->locale ?: $request->session()->get('locale');
        $locale = in_array($locale, $available, true) ? $locale : config('app.locale');

        app()->setLocale($locale);
        Carbon::setLocale($locale);

        return $next($request);
    }
}
