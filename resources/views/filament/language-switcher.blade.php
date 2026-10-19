<form method="POST" class="gf-locale" style="display:flex;gap:.25rem;align-items:center;margin-inline-end:.5rem" aria-label="{{ __('Язык') }}">
    @csrf
    @foreach (config('gifter.locales') as $code => $name)
        <button type="submit" formaction="{{ route('locale.switch', $code) }}" title="{{ $name }}"
           style="padding:.15rem .5rem;border-radius:.375rem;font-size:.8125rem;border:0;cursor:pointer;background:{{ app()->getLocale() === $code ? 'rgb(245 158 11)' : 'transparent' }};color:{{ app()->getLocale() === $code ? '#fff' : 'inherit' }};font-weight:{{ app()->getLocale() === $code ? '600' : '400' }};opacity:{{ app()->getLocale() === $code ? '1' : '.75' }}">{{ strtoupper($code) }}</button>
    @endforeach
</form>
