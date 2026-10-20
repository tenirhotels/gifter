<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Публичные страницы зависят от языка посетителя: не кешировать (в т. ч. в Cloudflare) и различать по языку. */
class PublicPage
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('Vary', 'Cookie, Accept-Language');

        return $response;
    }
}
