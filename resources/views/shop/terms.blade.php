@extends('shop.layout')
@section('title', __('Условия сертификата').' — '.config('gifter.certificate.hotel_name'))
@section('content')
<div class="wrap"><div class="prose">
    <h1>{{ __('Условия сертификата') }}</h1>
    <ol>@foreach ($shop->terms() as $term)<li>{{ $term }}</li>@endforeach</ol>
</div></div>
@endsection
