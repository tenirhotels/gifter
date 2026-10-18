<!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<title>Сертификат {{ $certificate->number }}</title>
<style>
    @page { margin: 18mm 16mm; }
    body { font-family: "DejaVu Sans", sans-serif; font-size: 10.5pt; color: #18181b; }
    .head { border-bottom: 2px solid #18181b; padding-bottom: 8pt; margin-bottom: 14pt; }
    .hotel { font-size: 11pt; font-weight: bold; letter-spacing: 1pt; }
    .title { font-size: 22pt; font-weight: bold; margin: 14pt 0 2pt; }
    .number { font-size: 14pt; font-family: "DejaVu Sans Mono", monospace; }
    table.meta { width: 100%; border-collapse: collapse; margin: 12pt 0; }
    table.meta td { padding: 4pt 0; vertical-align: top; }
    table.meta td.k { width: 32%; color: #52525b; }
    table.items { width: 100%; border-collapse: collapse; margin-top: 6pt; }
    table.items th { text-align: left; border-bottom: 1px solid #a1a1aa; padding: 4pt 0; color: #52525b; font-weight: normal; }
    table.items td { padding: 4pt 0; border-bottom: 1px solid #e4e4e7; }
    table.items td.q { text-align: right; width: 18%; padding-right: 10pt; }
    table.items td.u, table.items th.u { width: 22%; color: #52525b; }
    .section { font-weight: bold; margin-top: 14pt; }
    .terms { margin-top: 6pt; padding-left: 14pt; color: #3f3f46; font-size: 9pt; }
    .qr { text-align: center; margin-top: 18pt; }
    .qr img { width: 34mm; height: 34mm; }
    .small { font-size: 8pt; color: #71717a; }
</style>
</head>
<body>
<div class="head">
    <div class="hotel">{{ $hotel['hotel_name'] }}</div>
    @if ($hotel['hotel_contacts'])<div class="small">{{ $hotel['hotel_contacts'] }}</div>@endif
</div>

<div class="title">Подарочный сертификат</div>
<div class="number">№ {{ $certificate->number }}</div>

<table class="meta">
    <tr><td class="k">Получатель</td><td>{{ $certificate->recipient?->full_name ?? 'Предъявитель сертификата' }}</td></tr>
    <tr><td class="k">Пакет</td><td>{{ $certificate->package?->name }}</td></tr>
    <tr><td class="k">Действует</td><td>с {{ $certificate->valid_from->format('d.m.Y') }} по {{ $certificate->valid_until->format('d.m.Y') }} включительно</td></tr>
</table>

<div class="section">В сертификат входит</div>
<table class="items">
    <thead><tr><th>Услуга</th><th class="q" style="text-align:right">Количество</th><th class="u">Единица</th></tr></thead>
    <tbody>
    @foreach ($certificate->items as $item)
        <tr><td>{{ $item->service_name_snapshot }}</td><td class="q">{{ $item->quantity }}</td><td class="u">{{ $item->unit_snapshot }}</td></tr>
    @endforeach
    </tbody>
</table>

<div class="section">Условия</div>
<ol class="terms">
    @foreach ($hotel['terms'] as $term)<li>{{ $term }}</li>@endforeach
</ol>

<div class="qr">
    <img src="{{ $qr }}" alt="QR">
    <div class="small">Проверка подлинности и срока: {{ $verifyUrl }}</div>
</div>
</body>
</html>
