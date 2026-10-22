@extends('shop.layout')
@section('title', $offer['package']->name.' — '.config('gifter.certificate.hotel_name'))
@section('description', \Illuminate\Support\Str::limit(strip_tags($offer['package']->description ?: __('Подарочный сертификат: :p1.', ['p1' => collect($offer['lines'])->pluck('name')->implode(', ')])), 200))
@section('image', $offer['package']->imageUrl() ?? '')
@section('wa_text', __('Здравствуйте! Хочу заказать сертификат «:p1».', ['p1' => $offer['package']->name]))
@section('jsonld')
{!! json_encode(array_filter([
    '@context' => 'https://schema.org', '@type' => 'Product',
    'name' => $offer['package']->name,
    'description' => strip_tags((string) $offer['package']->description) ?: null,
    'image' => $offer['package']->imageUrl(),
    'brand' => ['@type' => 'Brand', 'name' => config('gifter.certificate.hotel_name')],
    'offers' => ['@type' => 'Offer', 'priceCurrency' => config('gifter.currency'), 'price' => (string) $offer['price'],
        'availability' => 'https://schema.org/InStock', 'url' => url()->current()],
]), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !!}
@endsection
@section('content')
<div class="wrap">
    <div class="product">
        <div class="sticky reveal">
            <div class="photo">@if ($offer['package']->imageUrl())<img src="{{ $offer['package']->imageUrl() }}" alt="{{ $offer['package']->name }}">@endif</div>
            @include('shop._certificate', ['name' => $offer['package']->name])
        </div>

        <div class="reveal">
            <a href="{{ \App\Support\ShopUrl::route('shop.index', [], 'gifts') }}" class="muted" style="text-decoration:none">← {{ __('Все сертификаты') }}</a>
            <h1 style="font-size:clamp(2.2rem,4.6vw,3.4rem);margin:14px 0 10px">{{ $offer['package']->name }}</h1>
            @if ($offer['package']->description)<p class="muted">{{ $offer['package']->description }}</p>@endif
            <ul class="includes" style="margin:20px 0">
                @foreach ($offer['lines'] as $line)<li>{{ $line['name'] }} × {{ $line['quantity'] }}</li>@endforeach
            </ul>
            <p class="muted">{{ __('Действует :p1', ['p1' => $offer['validity']]) }}</p>
            <div class="total"><span class="muted">{{ __('Стоимость') }}</span><span class="price">{{ \App\Support\Money::format($offer['price']) }}</span></div>

            <form method="post" action="{{ \App\Support\ShopUrl::route('shop.store', $offer['package']) }}" style="margin-top:36px">
                @csrf
                <fieldset>
                    <legend><i>1</i>{{ __('Для кого') }}</legend>
                    <label class="f">{{ __('Получатель') }} <span class="hint">{{ __('Необязательно: фамилия и имя.') }}</span><input type="text" name="recipient_name" value="{{ old('recipient_name') }}" maxlength="255"></label>
                    <label class="f">{{ __('Поздравление') }} <span class="hint">{{ __('Несколько тёплых слов для получателя.') }}</span><textarea name="gift_message" maxlength="500">{{ old('gift_message') }}</textarea></label>
                </fieldset>

                <fieldset>
                    <legend><i>2</i>{{ __('Ваши данные') }}</legend>
                    <label class="f">{{ __('Фамилия') }}<input type="text" name="last_name" value="{{ old('last_name') }}" required maxlength="100" autocomplete="family-name">@error('last_name')<span class="err">{{ $message }}</span>@enderror</label>
                    <label class="f">{{ __('Имя') }}<input type="text" name="first_name" value="{{ old('first_name') }}" required maxlength="100" autocomplete="given-name">@error('first_name')<span class="err">{{ $message }}</span>@enderror</label>
                    <label class="f">{{ __('Телефон') }}<input type="tel" name="phone" value="{{ old('phone') }}" required maxlength="32" placeholder="+7 701 123 45 67" autocomplete="tel">@error('phone')<span class="err">{{ $message }}</span>@enderror</label>
                    <label class="f">Email <span class="hint">{{ __('Пришлём подтверждение заявки.') }}</span><input type="email" name="email" value="{{ old('email') }}" maxlength="255" autocomplete="email">@error('email')<span class="err">{{ $message }}</span>@enderror</label>
                </fieldset>

                <fieldset>
                    <legend><i>3</i>{{ __('Получение') }}</legend>
                    <div class="opts" id="delivery">
                        @foreach (\App\Enums\DeliveryMethod::cases() as $method)
                            <label class="opt"><input type="radio" name="delivery_method" value="{{ $method->value }}" @checked(old('delivery_method', 'pickup') === $method->value)>
                                <span><b>{{ $method->getLabel() }}</b>
                                    <span class="hint">@switch($method->value)
                                        @case('pickup'){{ __('Менеджер согласует время, когда сертификат будет готов.') }}@break
                                        @case('courier'){{ __('Доставим по городу; стоимость и время согласует менеджер.') }}@break
                                        @default{{ __('Выпущенный сертификат придёт на email.') }}
                                    @endswitch</span></span></label>
                        @endforeach
                    </div>
                    <label class="f" id="addr" style="display:none">{{ __('Адрес доставки') }}<textarea name="delivery_address" maxlength="500">{{ old('delivery_address') }}</textarea>@error('delivery_address')<span class="err">{{ $message }}</span>@enderror</label>
                    <label class="f">{{ __('Пожелания к заказу') }}<textarea name="notes" maxlength="1000">{{ old('notes') }}</textarea></label>
                </fieldset>

                <div class="hp" aria-hidden="true"><label>Website<input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>
                <label class="check"><input type="checkbox" name="consent" value="1" @checked(old('consent')) required><span>{{ __('Согласен на обработку персональных данных для оформления заявки.') }} <a href="{{ \App\Support\ShopUrl::route('shop.privacy') }}" target="_blank">{{ __('Политика конфиденциальности') }}</a>@if ($shop->offerUrl()) · <a href="{{ $shop->offerUrl() }}" target="_blank" rel="noopener">{{ __('Публичная оферта') }}</a>@endif</span></label>
                @error('consent')<span class="err">{{ $message }}</span>@enderror
                <button class="btn" type="submit" style="margin-top:20px;width:100%">{{ __('Отправить заявку') }}</button>
                <p class="hint" style="margin-top:12px;text-align:center">{{ __('Менеджер свяжется с вами для завершения заказа и оплаты.') }}</p>
            </form>
        </div>
    </div>
</div>
<script>
    (function () {
        var radios = document.querySelectorAll('input[name=delivery_method]'), addr = document.getElementById('addr');
        function sync() { var v = document.querySelector('input[name=delivery_method]:checked'); addr.style.display = v && v.value === 'courier' ? 'grid' : 'none'; }
        radios.forEach(function (r) { r.addEventListener('change', sync); }); sync();
    })();
</script>
@endsection
