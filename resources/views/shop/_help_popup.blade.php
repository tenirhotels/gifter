{{-- Всплывающее окно «Затрудняетесь?»: через N секунд «на виду», один раз за визит, не мешает вводу в форму. Номер — тот же, что у WhatsApp. --}}
@php
    $waText = trim($__env->yieldContent('wa_text')) ?: __('Здравствуйте! Хочу заказать подарочный сертификат.');
@endphp
<div class="help-pop" id="help-pop" role="dialog" aria-modal="true" aria-labelledby="help-pop-title" hidden>
    <button type="button" class="help-x" id="help-close" aria-label="{{ __('Закрыть') }}">×</button>
    <h2 id="help-pop-title">{{ $shop->text('help_popup_title', __('Затрудняетесь с выбором?')) }}</h2>
    <p>{{ $shop->text('help_popup_text', __('Позвоните нам или напишите в WhatsApp — поможем подобрать сертификат.')) }}</p>
    <div class="help-actions">
        <a class="btn" data-contact="call" href="tel:+{{ $shop->whatsapp() }}">{{ __('Позвонить') }}</a>
        <a class="btn light" data-contact="whatsapp" href="https://wa.me/{{ $shop->whatsapp() }}?text={{ rawurlencode($waText) }}" target="_blank" rel="noopener">{{ __('Написать в WhatsApp') }}</a>
    </div>
</div>
<style>
    .help-pop { position: fixed; right: 18px; bottom: 18px; z-index: 40; width: min(24rem, calc(100vw - 36px)); padding: 24px 22px 20px; border-radius: 20px; background: #fff; color: var(--ink); box-shadow: 0 18px 50px rgba(34, 48, 44, .28); animation: help-in .35s var(--ease); }
    .help-pop[hidden] { display: none; }
    .help-pop h2 { margin: 0 24px 8px 0; font-size: 1.25rem; }
    .help-pop p { margin: 0 0 16px; color: var(--muted); line-height: 1.5; }
    .help-actions { display: flex; flex-wrap: wrap; gap: 10px; }
    .help-actions .btn { padding: 10px 18px; }
    .help-x { position: absolute; top: 8px; right: 10px; width: 36px; height: 36px; border: 0; background: none; font-size: 1.5rem; line-height: 1; cursor: pointer; color: var(--muted); }
    @keyframes help-in { from { opacity: 0; transform: translateY(12px); } to { opacity: 1; transform: none; } }
    @media (prefers-reduced-motion: reduce) { .help-pop { animation: none; } }
    @media (max-width: 520px) { .help-pop { left: 12px; right: 12px; bottom: 12px; width: auto; } }
</style>
<script>
    (function () {
        var pop = document.getElementById('help-pop'), delay = {{ $shop->helpPopupDelay() }}, seen = 0, postponed = 0, shown = false;
        try { if (sessionStorage.getItem('gifter_help_shown')) return; } catch (e) {}
        function typing() {
            var fields = document.querySelectorAll('form input[type=text], form input[type=tel], form input[type=email], form textarea');
            for (var i = 0; i < fields.length; i++) { if (fields[i].value && fields[i].value.trim() !== '' && !fields[i].closest('.hp')) return true; }
            return false;
        }
        function close() { pop.hidden = true; }
        document.getElementById('help-close').addEventListener('click', close);
        document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !pop.hidden) close(); });
        var timer = setInterval(function () {
            if (document.hidden || shown) return;
            if (++seen < delay) return;
            if (typing() && postponed < 2) { postponed++; seen = Math.max(0, delay - 30); return; }
            if (typing()) { clearInterval(timer); return; }
            shown = true; clearInterval(timer);
            pop.hidden = false;
            try { sessionStorage.setItem('gifter_help_shown', '1'); } catch (e) {}
        }, 1000);
    })();
</script>
