@extends('shop.layout')
@section('title', __('Вопросы и ответы').' — '.config('gifter.certificate.hotel_name'))
@section('content')
<div class="wrap"><div class="prose">
    <h1>{{ __('Вопросы и ответы') }}</h1>
    <details open><summary>{{ __('Как купить сертификат?') }}</summary><p>{{ __('Выберите сертификат и оставьте заявку. Менеджер свяжется с вами, выставит счёт Kaspi или Halyk Bank, а после оплаты выпустит сертификат.') }}</p></details>
    <details><summary>{{ __('Как я получу сертификат?') }}</summary><p>{{ __('Можно забрать в отеле, заказать курьера по городу или получить печатный PDF на email.') }}</p></details>
    <details><summary>{{ __('Сколько действует сертификат?') }}</summary><p>{{ __('Срок указан на странице сертификата и на самом сертификате; он считается с даты продажи.') }}</p></details>
    <details><summary>{{ __('Как забронировать проживание или услугу?') }}</summary><p>{{ __('Свяжитесь с отелем заранее и назовите номер сертификата.') }}</p></details>
    <p style="margin-top:28px"><a href="{{ route('shop.terms') }}">{{ __('Условия сертификата') }}</a></p>
</div></div>
@endsection
