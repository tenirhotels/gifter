{{-- Плавающая кнопка WhatsApp и уведомление о cookie (только если подключена аналитика). --}}
@if ($shop->whatsapp())
    <a class="wa" href="https://wa.me/{{ $shop->whatsapp() }}?text={{ rawurlencode(trim($__env->yieldContent('wa_text')) ?: __('Здравствуйте! Хочу заказать подарочный сертификат.')) }}"
       target="_blank" rel="noopener" aria-label="{{ __('Написать в WhatsApp') }}">
        <svg viewBox="0 0 24 24" width="22" height="22" fill="currentColor" aria-hidden="true"><path d="M12.04 2a9.9 9.9 0 0 0-8.5 14.9L2 22l5.25-1.5A9.95 9.95 0 1 0 12.04 2Zm5.8 14.1c-.25.7-1.4 1.3-1.95 1.35-.5.05-1.1.07-1.75-.12a15 15 0 0 1-1.6-.6c-2.8-1.2-4.6-4-4.75-4.2-.14-.18-1.15-1.5-1.15-2.9s.73-2.05 1-2.35c.25-.28.55-.35.74-.35h.53c.17 0 .4-.06.62.48.25.55.8 1.9.87 2.04.07.14.1.3 0 .48-.1.18-.14.3-.28.46-.14.16-.3.36-.42.48-.14.14-.28.3-.12.57.16.28.7 1.15 1.5 1.86 1.04.92 1.9 1.2 2.18 1.34.28.14.44.12.6-.07.16-.18.7-.82.88-1.1.18-.28.37-.23.62-.14.25.1 1.6.76 1.88.9.27.14.46.2.52.32.07.1.07.66-.18 1.36Z"/></svg>
        <span>WhatsApp</span>
    </a>
@endif
@if ($shop->hasAnalytics())
    <div class="cookie" id="cookie-note" hidden>
        <span>{{ __('Сайт использует cookie: запоминает язык и помогает нам понять, какие страницы полезны.') }} <a href="{{ \App\Support\ShopUrl::route('shop.privacy') }}" style="color:#fff;text-decoration:underline">{{ __('Подробнее') }}</a></span>
        <button type="button" class="btn light" id="cookie-ok">{{ __('Понятно') }}</button>
    </div>
    <script>
        (function () {
            var note = document.getElementById('cookie-note');
            try { if (localStorage.getItem('gifter_cookie_ok')) return; } catch (e) {}
            note.hidden = false;
            document.getElementById('cookie-ok').addEventListener('click', function () {
                note.hidden = true;
                try { localStorage.setItem('gifter_cookie_ok', '1'); } catch (e) {}
            });
        })();
    </script>
@endif
