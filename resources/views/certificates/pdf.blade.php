<!doctype html>
<html lang="{{ app()->getLocale() }}">
<head>
<meta charset="utf-8">
<title>{{ __('Сертификаты') }}</title>
{{-- Стиль бренда TENIR Eco Hotels: Dark Green #5F7872, Ochre #EAA55A. Gill Sans не встраивается — DejaVu (кириллица и казахские буквы). --}}
<style>
    @page { margin: 12mm; }
    body { font-family: "DejaVu Sans", sans-serif; font-size: 9.5pt; color: #2f3b38; margin: 0; }
    .page { border: 1.2pt solid #5F7872; padding: 3mm; height: 264mm; }
    .inner { border: 0.5pt solid #EAA55A; height: 253mm; padding: 10mm 14mm 0; position: relative; }
    .logo { text-align: center; }
    .logo img { height: 30mm; }
    .kicker { text-align: center; font-size: 8pt; letter-spacing: 3pt; text-transform: uppercase; color: #5F7872; margin-top: 7mm; }
    .title { text-align: center; font-family: "DejaVu Serif", serif; font-size: 25pt; color: #2f3b38; margin: 2mm 0 0; }
    .rule { width: 26mm; border-top: 1.2pt solid #EAA55A; margin: 5mm auto; }
    .for { text-align: center; font-size: 8pt; letter-spacing: 2pt; text-transform: uppercase; color: #7b8a86; }
    .recipient { text-align: center; font-family: "DejaVu Serif", serif; font-size: 17pt; margin-top: 1.5mm; }
    .message { text-align: center; font-family: "DejaVu Serif", serif; font-style: italic; font-size: 11pt; color: #4a5a56; margin: 5mm 12mm 0; line-height: 1.45; }
    .package { text-align: center; font-size: 12pt; font-weight: bold; color: #5F7872; margin-top: 7mm; }
    table.items { width: 76%; margin: 3mm auto 0; border-collapse: collapse; }
    table.items td { padding: 2.2pt 0; border-bottom: 0.5pt solid #e3e8e6; }
    table.items.compact td { padding: 1pt 0; font-size: 8pt; }
    table.items td.q { text-align: right; width: 30%; color: #5f6d69; white-space: nowrap; }
    .validity { text-align: center; margin-top: 6mm; font-size: 10pt; }
    .validity strong { color: #2f3b38; }
    .bottom { position: absolute; left: 14mm; right: 14mm; bottom: 8mm; }
    table.foot { width: 100%; border-collapse: collapse; }
    table.foot td { vertical-align: bottom; }
    .number-label { font-size: 7.5pt; letter-spacing: 2pt; text-transform: uppercase; color: #7b8a86; }
    .number { font-family: "DejaVu Sans Mono", monospace; font-size: 14pt; color: #2f3b38; }
    .terms { margin: 3mm 0 0; padding-left: 11pt; color: #5f6d69; font-size: 7.3pt; line-height: 1.35; }
    .qr { text-align: center; width: 44mm; }
    .qr img { width: 30mm; height: 30mm; }
    .small { font-size: 7pt; color: #7b8a86; }
    .hotel { font-size: 7.5pt; color: #5F7872; margin-top: 2mm; }
    .page + .page { page-break-before: always; }
</style>
</head>
<body>
@foreach ($pages as $page)
@php($certificate = $page['certificate'])
<div class="page"><div class="inner">
    <div class="logo">
        @if ($logo)<img src="{{ $logo }}" alt="{{ $hotel['hotel_name'] }}">@else<strong>{{ $hotel['hotel_name'] }}</strong>@endif
    </div>

    <div class="kicker">{{ $hotel['hotel_name'] }}</div>
    <div class="title">{{ __('Подарочный сертификат') }}</div>
    <div class="rule"></div>

    <div class="for">{{ __('Получатель') }}</div>
    <div class="recipient">{{ $certificate->recipient?->full_name ?? __('Предъявитель сертификата') }}</div>

    @if ($certificate->gift_message)
        <div class="message">«{{ $certificate->gift_message }}»</div>
    @endif

    <div class="package">{{ $certificate->package?->localizedName() }}</div>
    <table class="items{{ $certificate->items->count() > 5 ? ' compact' : '' }}">
        @foreach ($certificate->items as $item)
            <tr><td>{{ $item->localizedName() }}</td><td class="q">{{ $item->quantity }} {{ $item->unit_snapshot }}</td></tr>
        @endforeach
    </table>

    <div class="validity">{{ __('Действует') }}: <strong>{{ __('с :p1 по :p2 включительно', ['p1' => $certificate->valid_from->format('d.m.Y'), 'p2' => $certificate->valid_until->format('d.m.Y')]) }}</strong></div>

    <div class="bottom">
        <table class="foot">
            <tr>
                <td>
                    <div class="number-label">{{ __('Номер сертификата') }}</div>
                    <div class="number">№ {{ $certificate->number }}</div>
                    <ol class="terms">
                        @foreach ($hotel['terms'] as $term)<li>{{ __($term) }}</li>@endforeach
                    </ol>
                    @if ($hotel['hotel_contacts'])<div class="hotel">{{ $hotel['hotel_contacts'] }}</div>@endif
                </td>
                <td class="qr">
                    <img src="{{ $page['qr'] }}" alt="QR">
                    <div class="small">{{ __('Проверка подлинности и срока') }}</div>
                </td>
            </tr>
        </table>
    </div>
</div></div>
@endforeach
</body>
</html>
