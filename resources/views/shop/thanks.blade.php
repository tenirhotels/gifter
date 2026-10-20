@extends('shop.layout')
@section('content')
<div class="wrap">
    <div class="prose" style="text-align:center;margin:0 auto">
        <div class="eyebrow">{{ __('Заявка принята') }}</div>
        <h1 style="margin-top:12px">{{ __('Спасибо!') }}</h1>
        <p class="muted" style="font-size:1.125rem">{{ __('Менеджер свяжется с вами для завершения заказа.') }}</p>
        <a class="btn" href="{{ \App\Support\ShopUrl::route('shop.index') }}" style="margin-top:20px">{{ __('Вернуться к сертификатам') }}</a>
    </div>
</div>
@endsection
