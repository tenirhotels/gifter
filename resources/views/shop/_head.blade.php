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
@if ($shop->hasAnalytics())
{{-- Свои визиты не считаем: адрес с ?notrack=1 запоминает флаг в браузере сотрудника (?notrack=0 — снять); счётчики тогда не загружаются, события гасятся. --}}
<script>(function () { try { var q = location.search; if (/[?&]notrack=1/.test(q)) localStorage.setItem('gifter_notrack', '1'); if (/[?&]notrack=0/.test(q)) localStorage.removeItem('gifter_notrack'); window.gifterNoTrack = !!localStorage.getItem('gifter_notrack'); } catch (e) {} if (window.gifterNoTrack) { window.gtag = window.ym = window.fbq = function () {}; } })();</script>
@endif
{{-- Счётчики ставят очередь событий сразу, а сами скрипты (GA4 178 КБ, Метрика 69 КБ, Meta) подгружаются после загрузки страницы, чтобы не мешать главной картинке. --}}
@if ($shop->ga4())
<script>window.dataLayer = window.dataLayer || []; function gtag() { dataLayer.push(arguments); } if (!window.gifterNoTrack) { gtag('js', new Date()); gtag('config', @json($shop->ga4())); window.addEventListener('load', function () { var g = document.createElement('script'); g.async = true; g.src = 'https://www.googletagmanager.com/gtag/js?id={{ $shop->ga4() }}'; document.head.appendChild(g); }); }</script>
@endif
@if ($shop->metrika())
<script>if (!window.gifterNoTrack) { window.ym = window.ym || function () { (window.ym.a = window.ym.a || []).push(arguments); }; window.ym.l = 1 * new Date(); ym({{ $shop->metrika() }}, 'init', { clickmap: true, trackLinks: true, accurateTrackBounce: true }); window.addEventListener('load', function () { var m = document.createElement('script'); m.async = true; m.src = 'https://mc.yandex.ru/metrika/tag.js'; document.head.appendChild(m); }); }</script>
@endif
@if ($shop->metaPixel())
<script>if (!window.gifterNoTrack) { !function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';n.queue=[];f.addEventListener('load',function(){t=b.createElement(e);t.async=!0;t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)})}(window,document,'script','https://connect.facebook.net/en_US/fbevents.js'); fbq('init', @json($shop->metaPixel())); fbq('track', 'PageView'); }</script>
<noscript><img height="1" width="1" style="display:none" alt="" src="https://www.facebook.com/tr?id={{ $shop->metaPixel() }}&amp;ev=PageView&amp;noscript=1"></noscript>
@endif
