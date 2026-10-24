@extends('shop.layout')
@section('title', __('Всё о сертификатах').' — '.config('gifter.certificate.hotel_name'))
@section('content')
@php $faq = $shop->faq(); @endphp
<div class="wrap"><div class="prose">
    <h1>{{ __('Всё о сертификатах') }}</h1>
    @foreach ($faq as $item)
        <details @if ($loop->first) open @endif><summary>{{ $item['q'] }}</summary>
            @foreach ($item['a'] as $paragraph)<p>{{ $paragraph }}</p>@endforeach</details>
    @endforeach
    <p style="margin-top:28px"><a href="{{ \App\Support\ShopUrl::route('shop.terms') }}">{{ __('Условия сертификата') }}</a></p>
</div></div>
@endsection
