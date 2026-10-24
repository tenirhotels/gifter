<!doctype html>
<html lang="{{ app()->getLocale() }}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="icon" type="image/svg+xml" href="/brand/favicon.svg">
<noscript><style>.reveal { opacity: 1; transform: none; }</style></noscript>
@include('shop._head')
<style>
@php
    // Шрифты бренда подключаются, только если файлы положены в public/fonts (см. public/fonts/README.md).
    $fontFaces = ['latin' => 'U+0000-00FF,U+0131,U+0152-0153,U+02BB-02BC,U+02C6,U+02DA,U+02DC,U+2000-206F,U+20AC,U+2122,U+2191,U+2193,U+2212,U+2215,U+FEFF,U+FFFD',
        'cyrillic' => 'U+0301,U+0400-045F,U+0490-0491,U+04B0-04B1,U+2116', 'cyrillic-ext' => 'U+0460-052F,U+1C80-1C8A,U+20B4,U+2DE0-2DFF,U+A640-A69F,U+FE2E-FE2F'];
@endphp
@if (is_file(public_path('fonts/gillsans.woff2')))
    @font-face { font-family: "Gill Sans"; font-style: normal; font-weight: 100 900; font-display: swap; src: url('/fonts/gillsans.woff2?v={{ filemtime(public_path('fonts/gillsans.woff2')) }}') format('woff2'); }
@endif
@foreach ($fontFaces as $subset => $range)
@if (is_file(public_path("fonts/montserrat-{$subset}.woff2")))
    @font-face { font-family: Montserrat; font-style: normal; font-weight: 400 600; font-display: swap; src: url('/fonts/montserrat-{{ $subset }}.woff2') format('woff2'); unicode-range: {{ $range }}; }
@endif
@endforeach
    :root {
        /* Брендбук TENIR Eco Hotels: Dark Green #5F7872, Ochre #EAA55A, Gill Sans (основной) и Montserrat (вспомогательный) */
        --ivory: #F7F6F2; --cream: #EDF0EC; --graphite: #22302C; --ink: #2B3633; --muted: #6F7A76; --line: #D9DEDA; --light-green: #B0BFBB;
        --accent: {{ $shop->accent() }}; --accent-ink: #fff; --warm: #EAA55A;
        --serif: "Gill Sans", "Gill Sans MT", "Gill Sans Nova", Lato, Calibri, "Trebuchet MS", sans-serif;
        --sans: "Gill Sans", "Gill Sans MT", "Gill Sans Nova", Lato, Calibri, "Trebuchet MS", sans-serif;
        --label: Montserrat, "Gill Sans", system-ui, sans-serif;
        --radius: 22px; --ease: cubic-bezier(.22, .61, .36, 1);
    }
    * { box-sizing: border-box; }
    html { scroll-behavior: smooth; }
    body { overflow-x: clip; }
    body { margin: 0; background: var(--ivory); color: var(--ink); font-family: var(--sans); line-height: 1.65; -webkit-font-smoothing: antialiased; }
    a { color: inherit; }
    h1, h2 { font-family: var(--serif); font-weight: 300; text-transform: uppercase; letter-spacing: .04em; line-height: 1.12; margin: 0; color: var(--graphite); }
    h3 { font-family: var(--serif); font-weight: 400; letter-spacing: .02em; line-height: 1.2; margin: 0; color: var(--graphite); }
    .wrap { max-width: 1180px; margin: 0 auto; padding: 0 24px; }
    .eyebrow { font: 500 .6875rem var(--label); letter-spacing: .26em; text-transform: uppercase; color: var(--accent); }
    .muted { color: var(--muted); }

    /* Шапка */
    .top { position: sticky; top: 0; z-index: 20; background: color-mix(in oklab, var(--ivory) 88%, transparent); backdrop-filter: blur(10px); border-bottom: 1px solid var(--line); }
    .top .wrap { display: flex; align-items: center; justify-content: space-between; height: 72px; gap: 16px; }
    .brand { display: block; color: var(--accent); line-height: 0; }
    .brand svg { height: 46px; width: auto; display: block; }
    .nav { display: flex; align-items: center; gap: 36px; font-size: 1.0625rem; }
    .nav .links { display: flex; gap: 32px; }
    .chip { display: inline-block; margin-top: 22px; padding: 8px 18px; border: 1px solid rgba(255,255,255,.45); border-radius: 999px; font: 500 .75rem var(--label); letter-spacing: .08em; color: #fff; }
    .feat { display: grid; gap: 18px; margin-top: 28px; }
    .feat div { display: grid; gap: 2px; padding-left: 18px; border-left: 1px solid var(--warm); }
    .feat b { font-weight: 500; color: var(--graphite); }
    .aud { display: grid; gap: 24px; grid-template-columns: repeat(4, 1fr); }
    @media (max-width: 900px) { .aud { grid-template-columns: repeat(2, 1fr); } }
    @media (max-width: 520px) { .aud { grid-template-columns: 1fr; } }
    .aud div { border-top: 1px solid var(--line); padding-top: 20px; }
    .aud h3 { font-size: 1.35rem; margin-bottom: 6px; }
    .nav a { text-decoration: none; color: var(--ink); font-weight: 500; transition: color .2s; }
    .nav a:hover { color: var(--accent); }
    .lang a { margin-left: 14px; font-size: .9375rem; letter-spacing: .1em; color: var(--muted); }
    .lang a.on { color: var(--graphite); font-weight: 700; }
    @media (max-width: 900px) { .nav { gap: 18px; font-size: 1rem; } .nav .links { gap: 18px; } }
    @media (max-width: 720px) { .top .wrap { height: auto; min-height: 64px; flex-wrap: wrap; padding-top: 8px; padding-bottom: 8px; row-gap: 4px; } .nav { width: 100%; justify-content: space-between; gap: 12px; font-size: .9375rem; } .nav .links { gap: 16px; flex-wrap: wrap; } .lang a { margin-left: 10px; } .brand svg { height: 38px; } }

    /* Кнопки */
    .btn { display: inline-flex; align-items: center; justify-content: center; gap: 8px; background: var(--accent); color: var(--accent-ink); border: 1px solid var(--accent); border-radius: 999px; padding: 15px 32px; font: 600 .9375rem var(--sans); letter-spacing: .04em; text-decoration: none; cursor: pointer; transition: transform .25s var(--ease), box-shadow .25s var(--ease), background .25s; }
    .btn:hover { transform: translateY(-2px); box-shadow: 0 10px 24px -10px color-mix(in oklab, var(--accent) 70%, #000); }
    .btn.ghost { background: transparent; color: var(--graphite); border-color: var(--graphite); }
    .btn.light { background: #fff; color: var(--graphite); border-color: #fff; }

    /* Hero */
    .hero { position: relative; min-height: 78vh; display: grid; align-items: end; color: #fff; background: linear-gradient(140deg, color-mix(in oklab, var(--accent) 80%, #000), var(--accent)); overflow: hidden; }
    .hero .bg { position: absolute; inset: 0; background-size: cover; background-position: center; transform: scale(1.04); animation: drift 18s var(--ease) infinite alternate; }
    .hero .mark { position: absolute; right: -4%; top: 6%; width: min(52vw, 640px); color: #fff; opacity: .1; }
    .hero.has-photo::after { content: ""; position: absolute; inset: 0; background: linear-gradient(180deg, rgba(18,28,25,.12) 0%, rgba(18,28,25,.7) 100%); }
    .hero .inner { position: relative; z-index: 1; padding: 120px 24px 72px; max-width: 1180px; margin: 0 auto; width: 100%; }
    .hero h1 { color: #fff; font-size: clamp(2.2rem, 5.6vw, 4.4rem); max-width: 16ch; font-weight: 300; }
    .hero p { max-width: 38rem; font-size: 1.125rem; opacity: .92; margin: 20px 0 32px; }
    @keyframes drift { to { transform: scale(1.1) translate(-1%, -1%); } }

    /* Секции */
    section { padding: 104px 0; }
    section.cream { background: var(--cream); }
    .split { display: grid; gap: 64px; align-items: center; grid-template-columns: 1fr 1fr; }
    @media (max-width: 860px) { section { padding: 72px 0; } .split { grid-template-columns: 1fr; gap: 40px; } }
    .section-head { max-width: 36rem; margin-bottom: 48px; }
    .section-head h2, .split h2 { font-size: clamp(2rem, 4vw, 3rem); margin: 12px 0 16px; }

    /* Карточки */
    .grid { display: grid; gap: 28px; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); }
    .card { display: flex; flex-direction: column; background: #fff; border-radius: var(--radius); overflow: hidden; text-decoration: none; box-shadow: 0 1px 0 var(--line), 0 20px 40px -32px rgba(38,38,42,.5); transition: transform .4s var(--ease), box-shadow .4s var(--ease); }
    .card:hover { transform: translateY(-6px); box-shadow: 0 1px 0 var(--line), 0 34px 60px -30px rgba(38,38,42,.55); }
    .card .ph { aspect-ratio: 3 / 2; overflow: hidden; background: linear-gradient(135deg, var(--cream), var(--light-green)); position: relative; }
    .card .ph img { width: 100%; height: 100%; object-fit: cover; display: block; transition: transform .8s var(--ease); }
    .card:hover .ph img { transform: scale(1.06); }
    .card .mono { position: absolute; inset: 0; display: grid; place-items: center; color: var(--accent); opacity: .35; }
    .card .mono svg { width: 38%; height: auto; }
    .card .body { padding: 26px 28px 30px; display: flex; flex-direction: column; gap: 10px; flex: 1; }
    .card h3 { font-size: 1.6rem; }
    .card .desc { color: var(--muted); font-size: .9375rem; }
    .period { color: var(--accent); font-size: .875rem; letter-spacing: .04em; margin: 0 0 6px; }
    .card .row { margin-top: auto; padding-top: 14px; display: flex; justify-content: space-between; align-items: baseline; border-top: 1px solid var(--line); }
    .price { font-family: var(--serif); font-weight: 300; font-size: 1.75rem; color: var(--graphite); }
    .more { font: 500 .6875rem var(--label); letter-spacing: .18em; text-transform: uppercase; color: var(--accent); }

    /* Сертификат как настоящий подарок */
    .cert { position: relative; aspect-ratio: 1.58 / 1; border-radius: 14px; padding: 14px; background: linear-gradient(145deg, #FFFFFF, #EAEFEB); box-shadow: 0 40px 60px -34px rgba(38,38,42,.55), 0 2px 0 rgba(255,255,255,.6) inset; transform: rotate(-2.5deg); transition: transform .6s var(--ease); }
    .cert:hover { transform: rotate(0) translateY(-4px); }
    .cert .frame { height: 100%; border: 1px solid var(--warm); border-radius: 8px; padding: 6px; }
    .cert .frame > div { height: 100%; border: 1px solid color-mix(in oklab, var(--warm) 55%, transparent); border-radius: 5px; display: flex; flex-direction: column; align-items: center; justify-content: center; text-align: center; gap: 8px; padding: 12px; }
    .cert .logo { color: var(--accent); }
    .cert .logo svg { height: clamp(38px, 8vw, 64px); width: auto; display: block; }
    .cert .rule { width: 56px; height: 1px; background: var(--warm); }
    .cert .kind { font: 500 .5625rem var(--label); letter-spacing: .3em; text-transform: uppercase; color: var(--accent); }
    .cert .name { font-family: var(--serif); font-weight: 300; text-transform: uppercase; letter-spacing: .06em; font-size: clamp(.95rem, 2.4vw, 1.4rem); color: var(--graphite); line-height: 1.2; }
    .cert .no { font-size: .625rem; letter-spacing: .22em; color: var(--muted); }
    .cert::before { content: ""; position: absolute; inset: 0; border-radius: 14px; background: linear-gradient(115deg, transparent 40%, rgba(255,255,255,.32) 50%, transparent 60%); background-size: 250% 100%; animation: sheen 6s var(--ease) infinite; pointer-events: none; }
    @keyframes sheen { 0% { background-position: 130% 0; } 60%, 100% { background-position: -130% 0; } }

    /* Шаги */
    .steps { display: grid; gap: 32px; grid-template-columns: repeat(4, 1fr); }
    @media (max-width: 900px) { .steps { grid-template-columns: repeat(2, 1fr); } }
    @media (max-width: 520px) { .steps { grid-template-columns: 1fr; } }
    .step { border-top: 1px solid var(--line); padding-top: 22px; }
    .step b { font-family: var(--serif); font-size: 2.6rem; color: var(--warm); font-weight: 300; display: block; line-height: 1; margin-bottom: 14px; }
    .step h3 { font-size: 1.4rem; margin-bottom: 6px; }

    /* Страница пакета и форма */
    .product { display: grid; gap: 64px; grid-template-columns: 1.05fr 1fr; align-items: start; padding: 56px 0 104px; }
    @media (max-width: 900px) { .product { grid-template-columns: 1fr; gap: 40px; } }
    .product .photo { border-radius: var(--radius); overflow: hidden; aspect-ratio: 4 / 3; background: linear-gradient(135deg, var(--cream), var(--light-green)); }
    .product .photo img { width: 100%; height: 100%; object-fit: cover; display: block; }
    .product .sticky { display: grid; gap: 28px; }
    /* Закрепление колонки с фото — только когда страница в две колонки; на телефоне блок идёт в потоке и не наезжает на форму. */
    @media (min-width: 901px) { .product .sticky { position: sticky; top: 100px; } }
    .includes { margin: 0; padding: 0; list-style: none; display: grid; gap: 8px; }
    .includes li { padding-left: 22px; position: relative; }
    .includes li::before { content: ""; position: absolute; left: 0; top: .72em; width: 10px; height: 1px; background: var(--accent); }
    fieldset { border: 0; padding: 0; margin: 0 0 32px; display: grid; gap: 16px; }
    legend { font-family: var(--serif); font-weight: 300; text-transform: uppercase; letter-spacing: .06em; font-size: 1.2rem; color: var(--graphite); padding: 0; margin-bottom: 12px; }
    legend i { font-style: normal; color: var(--warm); margin-right: 10px; }
    label.f { display: grid; gap: 6px; font-size: .8125rem; font-weight: 600; letter-spacing: .04em; color: var(--ink); }
    input[type=text], input[type=tel], input[type=email], textarea { width: 100%; padding: 14px 16px; border: 1px solid var(--line); border-radius: 12px; font: 400 1rem var(--sans); background: #fff; color: var(--ink); transition: border-color .2s, box-shadow .2s; }
    input:focus, textarea:focus { outline: 0; border-color: var(--accent); box-shadow: 0 0 0 4px color-mix(in oklab, var(--accent) 18%, transparent); }
    textarea { min-height: 96px; resize: vertical; }
    .hint { font-weight: 400; color: var(--muted); font-size: .8125rem; letter-spacing: 0; }
    .err { color: #b3261e; font-size: .8125rem; font-weight: 500; }
    .opts { display: grid; gap: 10px; }
    .opt { display: flex; gap: 12px; align-items: flex-start; padding: 16px 18px; border: 1px solid var(--line); border-radius: 14px; background: #fff; cursor: pointer; transition: border-color .2s, background .2s; }
    .opt:has(input:checked) { border-color: var(--accent); background: color-mix(in oklab, var(--accent) 7%, #fff); }
    .opt input { margin-top: 5px; accent-color: var(--accent); }
    .opt b { display: block; font-weight: 600; }
    .check { display: flex; gap: 10px; align-items: flex-start; font-size: .875rem; color: var(--muted); }
    .check input { margin-top: 4px; accent-color: var(--accent); }
    .hp { position: absolute; left: -10000px; width: 1px; height: 1px; overflow: hidden; }
    .total { display: flex; justify-content: space-between; align-items: baseline; border-top: 1px solid var(--line); padding-top: 18px; margin-bottom: 18px; }

    /* Подвал */
    footer { background: var(--accent); color: #eef2f0; padding: 64px 0 40px; font-size: .9rem; }
    footer .cols { display: grid; gap: 40px; grid-template-columns: 1.4fr 1fr 1fr; }
    @media (max-width: 760px) { footer .cols { grid-template-columns: 1fr; } }
    footer .brand { color: #fff; }
    footer .brand svg { height: 64px; }
    footer a { color: #eef2f0; text-decoration: none; display: block; margin: 6px 0; }
    footer a:hover { color: #fff; }
    footer small { display: block; margin-top: 36px; color: color-mix(in oklab, #fff 65%, var(--accent)); }

    .prose { max-width: 44rem; padding: 64px 0 104px; }
    .prose h1 { font-size: clamp(2.2rem, 5vw, 3.4rem); margin-bottom: 24px; }
    .prose li, .prose p { color: var(--ink); }
    .prose details { border-top: 1px solid var(--line); padding: 18px 0; }
    .prose summary { cursor: pointer; font-family: var(--serif); font-size: 1.25rem; color: var(--graphite); }

    /* WhatsApp и уведомление о cookie */
    .wa { position: fixed; right: 18px; bottom: 18px; z-index: 30; display: inline-flex; align-items: center; gap: 8px; padding: 12px 18px; border-radius: 999px; background: #25D366; color: #fff; text-decoration: none; font: 600 .875rem var(--sans); box-shadow: 0 10px 24px -10px rgba(0,0,0,.45); transition: transform .25s var(--ease); }
    .wa:hover { transform: translateY(-2px); }
    @media (max-width: 520px) { .wa span { display: none; } .wa { padding: 14px; } }
    .cookie { position: fixed; left: 18px; bottom: 18px; z-index: 29; max-width: 26rem; display: flex; gap: 14px; align-items: center; padding: 14px 18px; border-radius: 16px; background: var(--graphite); color: #fff; font-size: .8125rem; line-height: 1.45; }
    .cookie[hidden] { display: none; }
    .cookie .btn { padding: 8px 16px; font-size: .8125rem; flex: none; }
    @media (max-width: 520px) { .cookie { right: 82px; left: 12px; bottom: 12px; flex-direction: column; align-items: flex-start; } }

    /* Появление при прокрутке */
    .reveal { opacity: 0; transform: translateY(22px); transition: opacity .9s var(--ease), transform .9s var(--ease); }
    .reveal.in { opacity: 1; transform: none; }
    @media (prefers-reduced-motion: reduce) { .reveal { opacity: 1; transform: none; transition: none; } .hero .bg, .cert::before { animation: none; } .btn, .card, .card .ph img, .cert { transition: none; } }
</style>
</head>
<body>
<header class="top">
    <div class="wrap">
        <a class="brand" href="{{ \App\Support\ShopUrl::route('shop.index') }}" aria-label="{{ config('gifter.certificate.hotel_name') }}">@include('shop._svg', ['name' => 'tenir-logo-horizontal'])</a>
        <nav class="nav">
            <span class="links">
                @if ($visitka ?? false)
                    <a href="{{ \App\Support\ShopUrl::route('shop.index', [], 'contact') }}">{{ __('Контакты') }}</a>
                @else
                    <a href="{{ \App\Support\ShopUrl::route('shop.index', [], 'gifts') }}">{{ __('Сертификаты') }}</a>
                    <a href="{{ \App\Support\ShopUrl::route('shop.index', [], 'how') }}">{{ __('Как это работает') }}</a>
                @endif
                <a href="{{ \App\Support\ShopUrl::route('shop.faq') }}">{{ __('Вопросы и ответы') }}</a>
            </span>
            <span class="lang">@foreach (array_keys(config('gifter.locales')) as $code)<a href="?lang={{ $code }}" @class(['on' => app()->getLocale() === $code])>{{ strtoupper($code) }}</a>@endforeach</span>
        </nav>
    </div>
</header>

@yield('content')

<footer>
    <div class="wrap">
        <div class="cols">
            <div>
                <div class="brand">@include('shop._svg', ['name' => 'tenir-logo'])</div>
            </div>
            <div>
                @unless ($visitka ?? false)
                    <a href="{{ \App\Support\ShopUrl::route('shop.index', [], 'gifts') }}">{{ __('Сертификаты') }}</a>
                    <a href="{{ \App\Support\ShopUrl::route('shop.index', [], 'how') }}">{{ __('Как это работает') }}</a>
                @endunless
                <a href="{{ \App\Support\ShopUrl::route('shop.faq') }}">{{ __('Вопросы и ответы') }}</a>
                <a href="{{ \App\Support\ShopUrl::route('shop.terms') }}">{{ __('Условия сертификата') }}</a>
                <a href="{{ \App\Support\ShopUrl::route('shop.privacy') }}">{{ __('Политика конфиденциальности') }}</a>
                @if ($shop->offerUrl())<a href="{{ $shop->offerUrl() }}" target="_blank" rel="noopener">{{ __('Публичная оферта') }}</a>@endif
                <a href="#contacts">{{ __('Контакты') }}</a>
            </div>
            <div id="contacts">
                <strong>{{ __('Контакты') }}</strong>
                <p>{{ config('gifter.certificate.hotel_contacts') ?: config('gifter.certificate.hotel_name') }}</p>
                <a href="/admin" style="margin-top:18px;opacity:.7">{{ __('Вход для сотрудников') }}</a>
            </div>
        </div>
        <small>© {{ date('Y') }} {{ config('gifter.certificate.hotel_name') }}</small>
    </div>
</footer>
@include('shop._widgets')
<script>
    (function () {
        var els = document.querySelectorAll('.reveal');
        if (!('IntersectionObserver' in window)) { els.forEach(function (e) { e.classList.add('in'); }); return; }
        var io = new IntersectionObserver(function (items) {
            items.forEach(function (i) { if (i.isIntersecting) { i.target.classList.add('in'); io.unobserve(i.target); } });
        }, { threshold: .12 });
        els.forEach(function (e) { io.observe(e); });
    })();
</script>
</body>
</html>
