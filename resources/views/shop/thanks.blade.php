@extends('shop.layout')
@section('robots', 'noindex, nofollow')
@section('content')
@if ($shop->ga4() || $shop->metrika())
<script>
    @if ($shop->ga4()) gtag('event', 'order_submitted'); @endif
    @if ($shop->metrika()) ym({{ $shop->metrika() }}, 'reachGoal', 'order_submitted'); @endif
</script>
@endif
<div class="wrap">
    <div class="prose" style="text-align:center;margin:0 auto">
        <div class="eyebrow">{{ __('Заявка принята') }}</div>
        <h1 style="margin-top:12px">{{ __('Спасибо!') }}</h1>
        <p class="muted" style="font-size:1.125rem">{{ __('Менеджер свяжется с вами для завершения заказа.') }}</p>
        <a class="btn" href="{{ \App\Support\ShopUrl::route('shop.index') }}" style="margin-top:20px">{{ __('Вернуться к сертификатам') }}</a>
    </div>
</div>
@endsection
