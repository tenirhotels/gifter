<?php

namespace App\Http\Controllers;

use Illuminate\Http\Response;

/** robots.txt и sitemap.xml публичной витрины. Служебные адреса (/admin, /v/, /order/) поисковикам не нужны. */
class SeoController extends Controller
{
    public function robots(): Response
    {
        $body = "User-agent: *\nDisallow: /admin\nDisallow: /v/\nDisallow: /order/thanks\nDisallow: /pay/\n\nSitemap: ".url('/sitemap.xml')."\n";

        return response($body, 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }

    public function sitemap(): Response
    {
        $paths = ['/', '/faq', '/terms'];
        foreach (app(PublicShopController::class)->offers() as $offer) {
            $paths[] = '/order/'.$offer['package']->id;
        }

        $locales = array_keys(config('gifter.locales'));
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n".'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:xhtml="http://www.w3.org/1999/xhtml">'."\n";
        foreach ($paths as $path) {
            $loc = e(url($path));
            $xml .= "<url><loc>{$loc}</loc>";
            foreach ($locales as $code) {
                $xml .= '<xhtml:link rel="alternate" hreflang="'.$code.'" href="'.e(url($path).'?lang='.$code).'"/>';
            }
            $xml .= "</url>\n";
        }

        return response($xml.'</urlset>'."\n", 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
    }
}
