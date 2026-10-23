@extends('shop.layout')
@php($route = fn (string $name, $params = [], ?string $fragment = null) => \App\Support\ShopUrl::route($name, $params, $fragment))
@php($visitka = $offers->isEmpty())
@section('content')
    <section class="hero {{ $shop->hero_image_path ? 'has-photo' : '' }}" style="padding:0">
        @if ($shop->hero_image_path)<div class="bg" style="background-image:url('{{ url('/media/'.basename($shop->hero_image_path)) }}')"></div>@else<div class="bg"></div><div class="mark">@include('shop._svg', ['name' => 'tenir-symbol'])</div>@endif
        <div class="inner">
            <div class="eyebrow" style="color:#fff;opacity:.85">TENIR</div>
            <h1 class="reveal" style="margin-top:14px">{{ $shop->text('hero_title', __('Подарите время, которое запомнится')) }}</h1>
            <p class="reveal" style="margin-bottom:14px;font-size:1.3rem">{{ $shop->text('hero_subtitle', __('Подарочные сертификаты на отдых в горах')) }}</p>
            <p class="reveal" style="margin-top:0">{{ $shop->text('hero_intro', __('Проживание в Tenir Eco Hotel, завтраки и особенные моменты среди гор. Выберите готовый сертификат — мы красиво оформим его и подготовим к вручению.')) }}</p>
            @if ($visitka)
                <a class="btn light reveal" href="#contact">{{ __('Связаться с нами') }}</a>
            @else
                <a class="btn light reveal" href="#gifts">{{ __('Выбрать сертификат') }}</a>
                <div class="reveal"><span class="chip">{{ __('Физический сертификат с доставкой') }}@if ($termNote) · {{ $termNote }}@endif</span></div>
            @endif
        </div>
    </section>

    <section class="cream">
        <div class="wrap split">
            <div class="reveal">
                <h2>{{ __('Подарок, который не будет пылиться на полке') }}</h2>
                <p class="muted" style="margin-top:16px">{{ __('Иногда лучший подарок — это возможность остановиться, сменить обстановку и провести время вместе.') }}</p>
                <p class="muted">{{ __('Подарите близкому человеку отдых в горах — а дату поездки он выберет сам.') }}</p>
                <div class="feat">
                    <div><b>{{ __('Проживание') }}</b><span class="muted">{{ __('Комфортный отдых в Tenir Eco Hotel.') }}</span></div>
                    <div><b>{{ __('Завтрак') }}</b><span class="muted">{{ __('Начните утро с завтрака и видами на горы.') }}</span></div>
                    <div><b>{{ __('Впечатления') }}</b><span class="muted">{{ __('Время вдали от города, которое хочется запомнить.') }}</span></div>
                </div>
            </div>
            @unless ($visitka)<div class="reveal">@include('shop._certificate', ['name' => $offers->first()['package']->localizedName()])</div>@endunless
        </div>
    </section>

    @if ($visitka)
    <section id="contact">
        <div class="wrap" style="text-align:center">
            <div class="section-head reveal" style="margin:0 auto 28px">
                <h2>{{ __('Свяжитесь с нами') }}</h2>
                <p class="muted">{{ $shop->text('contact_invite', __('Подарочные сертификаты TENIR — свяжитесь с нами, и мы подберём и оформим подарок.')) }}</p>
            </div>
            @if (config('gifter.certificate.hotel_contacts'))<p class="reveal">{{ config('gifter.certificate.hotel_contacts') }}</p>@endif
            @if ($shop->whatsapp())<p class="reveal"><a class="btn" href="https://wa.me/{{ $shop->whatsapp() }}?text={{ rawurlencode(__('Здравствуйте! Хочу заказать подарочный сертификат.')) }}" target="_blank" rel="noopener">WhatsApp</a></p>@endif
        </div>
    </section>
    @else
    <section id="gifts">
        <div class="wrap">
            <div class="section-head reveal">
                <h2>{{ __('Выберите свой подарок') }}</h2>
                <p class="muted">{{ __('Сертификаты TENIR созданы для разных поводов — от романтического сюрприза до подарка родителям, друзьям или деловым партнёрам.') }}</p>
            </div>
                <div class="grid" id="cards">
                    @foreach ($offers as $offer)
                        <a class="card reveal" href="{{ $route('shop.order', $offer['package']) }}">
                            <div class="ph">
                                @if ($offer['package']->imageUrl())<img src="{{ $offer['package']->imageUrl() }}" alt="{{ $offer['package']->localizedName() }}" loading="lazy">@else<div class="mono">@include('shop._svg', ['name' => 'tenir-symbol'])</div>@endif
                            </div>
                            <div class="body">
                                <h3>{{ $offer['package']->localizedName() }}</h3>
                                @if ($offer['package']->localizedDescription())<p class="desc">{{ \Illuminate\Support\Str::limit($offer['package']->localizedDescription(), 120) }}</p>@endif
                                <div class="row"><span class="price">{{ \App\Support\Money::format($offer['price']) }}</span><span class="more">{{ __('Подробнее') }} →</span></div>
                            </div>
                        </a>
                    @endforeach
                </div>
        </div>
    </section>

    @endif

    <section class="cream">
        <div class="wrap">
            <div class="section-head reveal"><h2>{{ __('Для тех, кому хочется подарить больше, чем вещь') }}</h2></div>
            <div class="aud">
                <div class="reveal"><h3>{{ __('Любимому человеку') }}</h3><p class="muted">{{ __('Подарите время вдвоём и возможность устроить себе маленькое путешествие.') }}</p></div>
                <div class="reveal"><h3>{{ __('Родителям') }}</h3><p class="muted">{{ __('Подарите отдых, который они действительно смогут использовать.') }}</p></div>
                <div class="reveal"><h3>{{ __('Друзьям') }}</h3><p class="muted">{{ __('Вместо очередного подарка — новые впечатления.') }}</p></div>
                <div class="reveal"><h3>{{ __('Коллегам и партнёрам') }}</h3><p class="muted">{{ __('Элегантный подарок, который можно вручить лично или доставить.') }}</p></div>
            </div>
        </div>
    </section>

    @unless ($visitka)
    <section id="how">
        <div class="wrap">
            <div class="section-head reveal"><h2>{{ __('Подарок готов за несколько минут') }}</h2></div>
            <div class="steps">
                <div class="step reveal"><b>1</b><h3>{{ __('Выберите сертификат') }}</h3></div>
                <div class="step reveal"><b>2</b><h3>{{ __('Оформите покупку') }}</h3></div>
                <div class="step reveal"><b>3</b><h3>{{ __('Получите красивый сертификат') }}</h3></div>
                <div class="step reveal"><b>4</b><h3>{{ __('Подарите его тому, кому хотите сделать приятно') }}</h3></div>
            </div>
            <p style="margin-top:44px" class="reveal"><a class="btn" href="#gifts">{{ __('Выбрать сертификат') }}</a></p>
        </div>
    </section>
    @endunless

    <section class="cream">
        <div class="wrap" style="text-align:center">
            <div class="section-head reveal" style="margin:0 auto 28px">
                <h2>{{ __('Остались вопросы?') }}</h2>
                <p class="muted">{{ __('Узнайте, как работает сертификат, какой у него срок действия и как воспользоваться им для бронирования.') }}</p>
            </div>
            <a class="btn ghost reveal" href="{{ $route('shop.faq') }}">{{ __('Вопросы и ответы') }}</a>
        </div>
    </section>
@endsection
