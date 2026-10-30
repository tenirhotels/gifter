{{-- SEO, превью ссылок в соцсетях и аналитика. Страницы задают секции: title, description, image, robots, jsonld. --}}
@php
    $hotel = config('gifter.certificate.hotel_name');
    $pageTitle = trim($__env->yieldContent('title')) ?: $hotel.' — '.__('Подарочные сертификаты');
    $pageDescription = trim($__env->yieldContent('description')) ?: __('Подарочные сертификаты отеля: проживание, ужины, спа и впечатления.');
    $pageImage = trim($__env->yieldContent('image')) ?: ($shop->hero_image_path ? url('/media/'.basename($shop->hero_image_path)) : null);
    $canonical = url()->current();
@endphp
<title>{{ $pageTitle }}</title>
<meta name="description" content="{{ $pageDescription }}">
<meta name="robots" content="{{ trim($__env->yieldContent('robots')) ?: 'index, follow' }}">
<link rel="canonical" href="{{ $canonical }}">
@foreach (array_keys(config('gifter.locales')) as $code)<link rel="alternate" hreflang="{{ $code }}" href="{{ $canonical }}?lang={{ $code }}">
@endforeach
<link rel="alternate" hreflang="x-default" href="{{ $canonical }}?lang={{ config('gifter.default_locale') }}">
<meta property="og:type" content="website">
<meta property="og:site_name" content="{{ $hotel }}">
<meta property="og:title" content="{{ $pageTitle }}">
<meta property="og:description" content="{{ $pageDescription }}">
<meta property="og:url" content="{{ $canonical }}">
<meta property="og:locale" content="{{ ['kk' => 'kk_KZ', 'ru' => 'ru_RU', 'en' => 'en_US'][app()->getLocale()] ?? 'kk_KZ' }}">
<meta name="twitter:card" content="{{ $pageImage ? 'summary_large_image' : 'summary' }}">
<meta name="twitter:title" content="{{ $pageTitle }}">
<meta name="twitter:description" content="{{ $pageDescription }}">
@if ($pageImage)<meta property="og:image" content="{{ $pageImage }}">
<meta name="twitter:image" content="{{ $pageImage }}">
@endif
@hasSection('jsonld')<script type="application/ld+json">{!! json_encode(trim($__env->yieldContent('jsonld')) ? json_decode($__env->yieldContent('jsonld'), true) : null, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) !!}</script>
@endif
@if ($shop->ga4())
<script async src="https://www.googletagmanager.com/gtag/js?id={{ $shop->ga4() }}"></script>
<script>window.dataLayer = window.dataLayer || []; function gtag() { dataLayer.push(arguments); } gtag('js', new Date()); gtag('config', @json($shop->ga4()));</script>
@endif
@if ($shop->metrika())
<script>(function (m, e, t, r, i, k, a) { m[i] = m[i] || function () { (m[i].a = m[i].a || []).push(arguments); }; m[i].l = 1 * new Date(); k = e.createElement(t), a = e.getElementsByTagName(t)[0], k.async = 1, k.src = r, a.parentNode.insertBefore(k, a); })(window, document, 'script', 'https://mc.yandex.ru/metrika/tag.js', 'ym'); ym({{ $shop->metrika() }}, 'init', { clickmap: true, trackLinks: true, accurateTrackBounce: true });</script>
@endif
@if ($shop->metaPixel())
<script>!function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';n.queue=[];t=b.createElement(e);t.async=!0;t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}(window,document,'script','https://connect.facebook.net/en_US/fbevents.js');fbq('init', @json($shop->metaPixel())); fbq('track', 'PageView');</script>
<noscript><img height="1" width="1" style="display:none" alt="" src="https://www.facebook.com/tr?id={{ $shop->metaPixel() }}&amp;ev=PageView&amp;noscript=1"></noscript>
@endif
