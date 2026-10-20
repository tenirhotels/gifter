@extends('shop.layout')
@section('content')
    <section class="hero {{ $shop->hero_image_path ? 'has-photo' : '' }}" style="padding:0">
        @if ($shop->hero_image_path)<div class="bg" style="background-image:url('{{ url('/media/'.basename($shop->hero_image_path)) }}')"></div>@else<div class="bg"></div><div class="mark">@include('shop._svg', ['name' => 'tenir-symbol'])</div>@endif
        <div class="inner">
            <div class="eyebrow" style="color:#fff;opacity:.85">{{ config('gifter.certificate.hotel_name') }}</div>
            <h1 class="reveal" style="margin-top:14px">{{ $shop->hero_title ?: __('Подарок, который хочется открыть') }}</h1>
            <p class="reveal">{{ $shop->hero_subtitle ?: __('Проживание, ужины, спа и впечатления в отеле — сертификат, который дарят с любовью.') }}</p>
            <a class="btn light reveal" href="#gifts">{{ __('Выбрать сертификат') }}</a>
        </div>
    </section>

    <section class="cream">
        <div class="wrap split">
            <div class="reveal">
                <div class="eyebrow">{{ __('С любовью') }}</div>
                <h2>{{ __('Лучшая часть ещё впереди') }}</h2>
                <p class="muted">{{ __('Сертификат — это способ сказать «спасибо», «люблю» или просто «думаю о тебе». Его можно использовать для проживания, ужинов, спа и впечатлений отеля.') }}</p>
                <a class="btn" href="#gifts" style="margin-top:12px">{{ __('Оставить заявку на сертификат') }}</a>
            </div>
            <div class="reveal">@include('shop._certificate', ['name' => $offers->first()['package']->name ?? __('Подарочный сертификат')])</div>
        </div>
    </section>

    <section id="gifts">
        <div class="wrap">
            <div class="section-head reveal">
                <div class="eyebrow">{{ __('Сертификаты') }}</div>
                <h2>{{ __('Впечатления на любой вкус') }}</h2>
            </div>
            @if ($offers->isEmpty())
                <p class="muted">{{ __('Сейчас онлайн-заявки недоступны. Свяжитесь с отелем.') }}</p>
            @else
                <div class="grid">
                    @foreach ($offers as $offer)
                        <a class="card reveal" href="{{ route('shop.order', $offer['package']) }}">
                            <div class="ph">
                                @if ($offer['package']->imageUrl())<img src="{{ $offer['package']->imageUrl() }}" alt="{{ $offer['package']->name }}" loading="lazy">@else<div class="mono">@include('shop._svg', ['name' => 'tenir-symbol'])</div>@endif
                            </div>
                            <div class="body">
                                <h3>{{ $offer['package']->name }}</h3>
                                @if ($offer['package']->description)<p class="desc">{{ \Illuminate\Support\Str::limit($offer['package']->description, 120) }}</p>@endif
                                <div class="row"><span class="price">{{ \App\Support\Money::format($offer['price']) }}</span><span class="more">{{ __('Подробнее') }} →</span></div>
                            </div>
                        </a>
                    @endforeach
                </div>
            @endif
        </div>
    </section>

    <section class="cream">
        <div class="wrap">
            <div class="section-head reveal">
                <div class="eyebrow">{{ __('Как это работает') }}</div>
                <h2>{{ __('Три простых шага') }}</h2>
            </div>
            <div class="steps">
                <div class="step reveal"><b>1</b><h3>{{ __('Оставьте заявку') }}</h3><p class="muted">{{ __('Выберите сертификат, укажите получателя и способ получения.') }}</p></div>
                <div class="step reveal"><b>2</b><h3>{{ __('Оплатите счёт') }}</h3><p class="muted">{{ __('Менеджер свяжется с вами и выставит счёт Kaspi или Halyk Bank.') }}</p></div>
                <div class="step reveal"><b>3</b><h3>{{ __('Подарите') }}</h3><p class="muted">{{ __('Заберите сертификат в отеле, закажите курьера или получите печатный PDF на email.') }}</p></div>
            </div>
        </div>
    </section>
@endsection
