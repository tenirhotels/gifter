@extends('shop.layout')
@section('content')
    <h1>{{ __('Заявка принята') }}</h1>
    <p class="lead">{{ __('Спасибо! Менеджер свяжется с вами, выставит счёт Kaspi или Halyk Bank, а после оплаты выпустит сертификат.') }}</p>
    <a class="btn" href="{{ route('shop.index') }}">{{ __('Вернуться к сертификатам') }}</a>
@endsection
