<?php

use App\Exceptions\DomainRuleViolation;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SetLocale;
use App\Support\ErrorReporter;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Хостинг за Cloudflare и nginx: доверяем X-Forwarded-* от прокси, иначе Laravel
        // считает запрос обычным HTTP и строит ссылки и формы с http://.
        $middleware->trustProxies(at: '*');
        $middleware->append(SecurityHeaders::class);
        $middleware->web(append: [SetLocale::class]);
        // Язык запоминается обычным cookie (его читает и страница без сессии), поэтому не шифруется.
        $middleware->encryptCookies(except: ['gifter_lang']);
        $middleware->validateCsrfTokens(except: ['pay/halyk/postlink', 'pay/kaspi-yespay/webhook']);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Нарушение бизнес-правила — не сбой сервера: сообщение пользователю, без письма и записи в журнал ошибок.
        $exceptions->dontReport(DomainRuleViolation::class);
        $exceptions->render(fn (DomainRuleViolation $e, Request $request) => response($e->getMessage(), 422));
        // Необработанные ошибки — письмом владельцу (ERROR_MAIL_TO), не чаще раза в 15 минут на одну ошибку.
        $exceptions->report(fn (Throwable $e) => ErrorReporter::report($e));
    })->create();
