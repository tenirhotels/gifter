@extends('shop.layout')
@section('title', config('gifter.certificate.hotel_name').' — '.__('Заявка на сертификат'))
@section('content')
    <h1>{{ __('Заявка на сертификат') }}</h1>
    <div class="summary">
        <strong>{{ $offer['package']->name }}</strong> · {{ \App\Support\Money::format($offer['price']) }}
        <div class="muted">{{ __('Действует :p1', ['p1' => $offer['validity']]) }}</div>
    </div>
    <form method="post" action="{{ route('shop.store', $offer['package']) }}">
        @csrf
        <label>{{ __('Фамилия') }}<input type="text" name="last_name" value="{{ old('last_name') }}" required maxlength="100" autocomplete="family-name">@error('last_name')<span class="err">{{ $message }}</span>@enderror</label>
        <label>{{ __('Имя') }}<input type="text" name="first_name" value="{{ old('first_name') }}" required maxlength="100" autocomplete="given-name">@error('first_name')<span class="err">{{ $message }}</span>@enderror</label>
        <label>{{ __('Телефон') }}<input type="tel" name="phone" value="{{ old('phone') }}" required maxlength="32" placeholder="+7 701 123 45 67" autocomplete="tel">@error('phone')<span class="err">{{ $message }}</span>@enderror</label>
        <label>Email<input type="email" name="email" value="{{ old('email') }}" maxlength="255" autocomplete="email"><span class="muted">{{ __('Необязательно: пришлём подтверждение заявки.') }}</span>@error('email')<span class="err">{{ $message }}</span>@enderror</label>
        <label>{{ __('Для кого сертификат') }}<input type="text" name="recipient_name" value="{{ old('recipient_name') }}" maxlength="255"><span class="muted">{{ __('Необязательно: фамилия и имя получателя.') }}</span></label>
        <label>{{ __('Пожелания') }}<textarea name="notes" maxlength="1000">{{ old('notes') }}</textarea></label>
        <div class="hp" aria-hidden="true"><label>Website<input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>
        <label class="check"><input type="checkbox" name="consent" value="1" @checked(old('consent')) required><span>{{ __('Согласен на обработку персональных данных для оформления заявки.') }}</span></label>
        @error('consent')<span class="err">{{ $message }}</span>@enderror
        <button class="btn" type="submit">{{ __('Отправить заявку') }}</button>
    </form>
@endsection
