<!doctype html>
<html lang="{{ app()->getLocale() }}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>@yield('title', config('gifter.certificate.hotel_name').' — '.__('Подарочные сертификаты'))</title>
<style>
    :root { --ink: #18181b; --muted: #71717a; --line: #e4e4e7; --bg: #fafafa; --card: #fff; --accent: #d97706; --accent-ink: #fff; --bad: #b91c1c; }
    @media (prefers-color-scheme: dark) { :root { --ink: #f4f4f5; --muted: #a1a1aa; --line: #3f3f46; --bg: #18181b; --card: #27272a; --accent: #f59e0b; --accent-ink: #18181b; --bad: #f87171; } }
    * { box-sizing: border-box; }
    body { margin: 0; font-family: system-ui, -apple-system, "Segoe UI", sans-serif; background: var(--bg); color: var(--ink); line-height: 1.5; }
    header, main, footer { max-width: 960px; margin: 0 auto; padding: 16px; }
    header { display: flex; justify-content: space-between; align-items: center; gap: 12px; }
    .brand { font-weight: 700; letter-spacing: .06em; font-size: 1.125rem; color: inherit; text-decoration: none; }
    .lang a { color: var(--muted); margin-left: 10px; text-decoration: none; font-size: .8125rem; }
    .lang a.on { color: var(--ink); font-weight: 600; }
    h1 { font-size: 1.75rem; line-height: 1.2; margin: .5rem 0 .25rem; }
    .lead { color: var(--muted); margin: 0 0 1.5rem; max-width: 40rem; }
    .grid { display: grid; gap: 16px; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); }
    .card { background: var(--card); border: 1px solid var(--line); border-radius: 12px; padding: 20px; display: flex; flex-direction: column; gap: 10px; }
    .card h2 { margin: 0; font-size: 1.125rem; }
    .price { font-size: 1.5rem; font-weight: 700; }
    .muted { color: var(--muted); font-size: .875rem; }
    ul.items { margin: 0; padding-left: 1.1rem; font-size: .875rem; }
    .btn { display: inline-block; text-align: center; background: var(--accent); color: var(--accent-ink); border: 0; border-radius: 8px; padding: 12px 18px; font-size: 1rem; font-weight: 600; text-decoration: none; cursor: pointer; margin-top: auto; }
    form { display: grid; gap: 14px; max-width: 520px; }
    label { display: grid; gap: 4px; font-size: .875rem; font-weight: 500; }
    input[type=text], input[type=tel], input[type=email], textarea { width: 100%; padding: 10px 12px; border: 1px solid var(--line); border-radius: 8px; font: inherit; background: var(--card); color: var(--ink); }
    textarea { min-height: 88px; }
    .check { display: flex; gap: 8px; align-items: flex-start; font-weight: 400; }
    .err { color: var(--bad); font-size: .8125rem; }
    .hp { position: absolute; left: -10000px; width: 1px; height: 1px; overflow: hidden; }
    .summary { background: var(--card); border: 1px solid var(--line); border-radius: 12px; padding: 16px; margin-bottom: 20px; max-width: 520px; }
    footer { color: var(--muted); font-size: .8125rem; }
    footer a { color: inherit; }
</style>
</head>
<body>
<header>
    <a class="brand" href="{{ route('shop.index') }}">{{ config('gifter.certificate.hotel_name') }}</a>
    <nav class="lang">@foreach (array_keys(config('gifter.locales')) as $code)<a href="?lang={{ $code }}" @class(['on' => app()->getLocale() === $code])>{{ strtoupper($code) }}</a>@endforeach</nav>
</header>
<main>@yield('content')</main>
<footer>
    @if (config('gifter.certificate.hotel_contacts'))<p>{{ config('gifter.certificate.hotel_contacts') }}</p>@endif
    <p><a href="/admin">{{ __('Вход для сотрудников') }}</a></p>
</footer>
</body>
</html>
