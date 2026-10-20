<!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>Проверка сертификата</title>
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
</style>
</head>
<body>
<main>
    <div class="card">
        <h1>{{ config('gifter.certificate.hotel_name') }} — проверка сертификата</h1>
        @if ($certificate)
            <dl>
                <dt>Номер</dt><dd>{{ $certificate->number }}</dd>
                <dt>Статус</dt><dd class="{{ $tone }}">{{ $statusText }}</dd>
                <dt>Действителен до</dt><dd>{{ $certificate->valid_until->format('d.m.Y') }}</dd>
                <dt>Пакет</dt><dd>{{ $certificate->package?->name }}</dd>
            </dl>
            <p class="small">Чтобы забронировать, свяжитесь с отелем{{ config('gifter.certificate.hotel_contacts') ? ': '.config('gifter.certificate.hotel_contacts') : '' }}.</p>
        @else
            <p class="bad">Сертификат не найден. Проверьте ссылку или обратитесь в отель.</p>
        @endif
    </div>
</main>
</body>
</html>
