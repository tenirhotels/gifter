{{-- Превью сертификата: как настоящий подарочный сертификат. Параметр: $name — название пакета. --}}
<div class="cert" aria-hidden="true">
    <div class="frame"><div>
        <div class="logo">@include('shop._svg', ['name' => 'tenir-logo-horizontal'])</div>
        <div class="rule"></div>
        <div class="kind">{{ __('Подарочный сертификат') }}</div>
        <div class="name">{{ $name }}</div>
        <div class="no">№ GIF · · · · · ·</div>
    </div></div>
</div>
