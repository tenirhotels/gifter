<div class="gf-locale" style="display:flex;gap:.25rem;align-items:center;margin-inline-end:.5rem" aria-label="{{ __('Язык') }}">
    @foreach (config('gifter.locales') as $code => $name)
        <a href="{{ route('locale.switch', $code) }}" title="{{ $name }}"
           style="padding:.15rem .5rem;border-radius:.375rem;font-size:.8125rem;text-decoration:none;{{ app()->getLocale() === $code ? 'background:rgb(245 158 11);color:#fff;font-weight:600' : 'color:inherit;opacity:.75' }}">{{ strtoupper($code) }}</a>
    @endforeach
</div>
