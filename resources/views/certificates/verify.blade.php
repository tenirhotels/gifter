<!doctype html>
<html lang="{{ app()->getLocale() }}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>{{ __('Проверка сертификата') }}</title>
<style>
    body { margin: 0; font-family: system-ui, -apple-system, "Segoe UI", sans-serif; background: #fafafa; color: #18181b; }
    main { max-width: 420px; margin: 0 auto; padding: 24px 16px; }
    .card { background: #fff; border: 1px solid #e4e4e7; border-radius: 8px; padding: 16px; }
    h1 { font-size: 16px; margin: 0 0 12px; }
    dl { display: grid; grid-template-columns: 40% 60%; gap: 8px 0; margin: 0; font-size: 14px; }
    dt { color: #71717a; }
    dd { margin: 0; font-weight: 600; }
    .ok { color: #15803d; } .warn { color: #b45309; } .bad { color: #b91c1c; }
    .small { font-size: 12px; color: #71717a; margin-top: 12px; }
    .lang { text-align: right; font-size: 12px; margin-bottom: 8px; }
    .lang a { color: #71717a; margin-left: 8px; text-decoration: none; }
    .lang a.on { color: #18181b; font-weight: 600; }
</style>
</head>
<body>
<main>
    <div class="lang">@foreach (array_keys(config('gifter.locales')) as $code)<a href="?lang={{ $code }}" @class(['on' => app()->getLocale() === $code])>{{ strtoupper($code) }}</a>@endforeach</div>
    <div class="card">
        <h1>{{ config('gifter.certificate.hotel_name') }} — {{ __('проверка сертификата') }}</h1>
        @if ($certificate)
            <dl>
                <dt>{{ __('Номер') }}</dt><dd>{{ $certificate->number }}</dd>
                <dt>{{ __('Статус') }}</dt><dd class="{{ $tone }}">{{ $statusText }}</dd>
                <dt>{{ __('Действителен до') }}</dt><dd>{{ $certificate->valid_until->format('d.m.Y') }}</dd>
                <dt>{{ __('Пакет') }}</dt><dd>{{ $certificate->package?->name }}</dd>
            </dl>
            <p class="small">{{ __('Чтобы забронировать, свяжитесь с отелем') }}{{ config('gifter.certificate.hotel_contacts') ? ': '.config('gifter.certificate.hotel_contacts') : '' }}.</p>
        @else
            <p class="bad">{{ __('Сертификат не найден. Проверьте ссылку или обратитесь в отель.') }}</p>
        @endif
    </div>
</main>
</body>
</html>
