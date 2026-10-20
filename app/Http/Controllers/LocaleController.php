<?php

namespace App\Http\Controllers;

use App\Support\LocaleResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Переключение языка интерфейса: запоминается в сессии и, если вошли, в профиле пользователя. */
class LocaleController extends Controller
{
    public function __invoke(Request $request, string $locale): RedirectResponse
    {
        abort_unless(array_key_exists($locale, config('gifter.locales')), 404);

        $request->session()->put('locale', $locale);
        $request->user()?->forceFill(['locale' => $locale])->save();

        return redirect()->back(fallback: '/admin')->withCookie(cookie(LocaleResolver::COOKIE, $locale, 60 * 24 * 365, '/', null, $request->isSecure(), false, false, 'lax'));
    }
}
