@extends('shop.layout')
@section('content')
    <h1>{{ __('Подарочные сертификаты') }}</h1>
    <p class="lead">{{ __('Выберите сертификат и оставьте заявку. Менеджер свяжется с вами, выставит счёт Kaspi или Halyk Bank, а после оплаты выпустит сертификат.') }}</p>
    @if ($offers->isEmpty())
        <p class="muted">{{ __('Сейчас онлайн-заявки недоступны. Свяжитесь с отелем.') }}</p>
    @else
        <div class="grid">
            @foreach ($offers as $offer)
                <article class="card">
                    <h2>{{ $offer['package']->name }}</h2>
                    @if ($offer['package']->description)<p class="muted">{{ $offer['package']->description }}</p>@endif
                    <ul class="items">
                        @foreach ($offer['lines'] as $line)<li>{{ $line['name'] }} × {{ $line['quantity'] }}</li>@endforeach
                    </ul>
                    <p class="muted">{{ __('Действует :p1', ['p1' => $offer['validity']]) }}</p>
                    <div class="price">{{ \App\Support\Money::format($offer['price']) }}</div>
                    <a class="btn" href="{{ route('shop.order', $offer['package']) }}">{{ __('Оставить заявку') }}</a>
                </article>
            @endforeach
        </div>
    @endif
@endsection
