<?php

namespace App\Models;

use App\Enums\DeliveryMethod;
use App\Support\LocalizedName;
use App\Support\PhoneNumber;
use Illuminate\Database\Eloquent\Model;

/** Оформление публичной страницы: главное фото, заголовок, акцентный цвет. Одна запись; пустое — значения по умолчанию. */
class ShopSetting extends Model
{
    public const DEFAULT_ACCENT = '#5F7872';

    public const DEFAULT_OPERATOR = 'ТОО «Alpine Hotel»';

    /** Тексты главной, которые правятся в настройках на трёх языках (русский — в колонке, kk/en — в `text_translations`). */
    public const TEXTS = ['hero_title', 'hero_subtitle', 'hero_intro', 'contact_invite'];

    protected $fillable = ['hero_image_path', 'hero_title', 'hero_subtitle', 'hero_intro', 'contact_invite', 'text_translations', 'delivery_methods', 'accent_color', 'ga4_id', 'metrika_id', 'whatsapp_phone', 'operator_name', 'offer_url', 'faq_items', 'terms_items', 'order_show_recipient', 'order_corporate_enabled', 'updated_by'];

    protected function casts(): array
    {
        return ['text_translations' => 'array', 'delivery_methods' => 'array', 'faq_items' => 'array', 'terms_items' => 'array', 'order_show_recipient' => 'boolean', 'order_corporate_enabled' => 'boolean'];
    }

    /** Стандартные вопросы и ответы (пока в настройках ничего не задано). Ответ — абзацы через перевод строки. */
    public const DEFAULT_FAQ = [
        ['Как купить сертификат?', ['Выберите понравившийся сертификат и оставьте заявку. Менеджер свяжется с вами, уточнит детали и оформит покупку.', 'После оплаты мы выпустим сертификат и передадим его выбранным вами способом.']],
        ['Как я получу сертификат?', ['Вы можете забрать сертификат в отеле, заказать доставку курьером по городу или получить электронный сертификат на email.']],
        ['Сколько действует сертификат?', ['Срок действия указан на странице выбранного сертификата и на самом сертификате. Срок начинает отсчитываться с даты продажи.']],
        ['Как воспользоваться сертификатом?', ['Когда будете готовы воспользоваться сертификатом, свяжитесь с отелем и назовите номер сертификата. Мы проверим его действительность и поможем оформить бронирование.', 'Для проживания рекомендуем обращаться заранее — выбранная дата зависит от доступности номеров.']],
        ['Можно ли выбрать дату проживания при покупке?', ['Не обязательно. Вы можете выбрать дату позже, в пределах срока действия сертификата и с учётом доступности номеров.']],
        ['Что входит в сертификат?', ['Состав зависит от выбранного сертификата. На странице каждого сертификата указаны стоимость, срок действия и точный состав подарка.']],
        ['Можно ли использовать сертификат частично?', ['Нет. Если сертификат включает несколько услуг, он используется целиком. Отдельные услуги нельзя использовать независимо друг от друга или вернуть их стоимость.']],
        ['Что делать, если срок действия сертификата заканчивается?', ['Если сертификат ещё не использован, заранее свяжитесь с отелем и уточните возможность бронирования до окончания срока его действия.']],
    ];

    /**
     * Вопросы и ответы на языке посетителя: из настроек, а если там пусто — стандартные. Ответ — абзацы.
     *
     * @return list<array{q: string, a: list<string>}>
     */
    public function faq(?string $lang = null): array
    {
        $items = [];
        foreach ((array) $this->faq_items as $item) {
            $q = LocalizedName::pick($item['q'] ?? null, '', $lang);
            $a = LocalizedName::pick($item['a'] ?? null, '', $lang);
            if ($q !== '' && $a !== '') {
                $items[] = ['q' => $q, 'a' => array_values(array_filter(array_map('trim', preg_split('/\R+/u', $a) ?: []), fn ($p) => $p !== ''))];
            }
        }
        if ($items !== []) {
            return $items;
        }

        return array_map(fn (array $row) => ['q' => __($row[0], [], $lang), 'a' => array_map(fn ($p) => __($p, [], $lang), $row[1])], self::DEFAULT_FAQ);
    }

    /** Условия сертификата на языке посетителя: из настроек, а если пусто — стандартные из конфигурации. @return list<string> */
    public function terms(?string $lang = null): array
    {
        $items = [];
        foreach ((array) $this->terms_items as $item) {
            $text = LocalizedName::pick($item['t'] ?? null, '', $lang);
            if ($text !== '') {
                $items[] = $text;
            }
        }

        return $items !== [] ? $items : array_map(fn ($t) => __($t, [], $lang), (array) config('gifter.certificate.terms'));
    }

    /** Стандартные вопросы для формы на трёх языках: [{q:{ru,kk,en}, a:{ru,kk,en}}]. @return list<array<string, array<string, string>>> */
    public static function defaultFaqItems(): array
    {
        return array_map(function (array $row): array {
            $item = ['q' => [], 'a' => []];
            foreach (LocalizedName::LANGS as $lang) {
                $item['q'][$lang] = __($row[0], [], $lang);
                $item['a'][$lang] = implode("\n", array_map(fn ($p) => __($p, [], $lang), $row[1]));
            }

            return $item;
        }, self::DEFAULT_FAQ);
    }

    /** @return list<array<string, array<string, string>>> */
    public static function defaultTermsItems(): array
    {
        return array_map(fn ($term) => ['t' => collect(LocalizedName::LANGS)->mapWithKeys(fn ($lang) => [$lang => __($term, [], $lang)])->all()], array_values((array) config('gifter.certificate.terms')));
    }

    public static function current(): self
    {
        return static::query()->first() ?? new static;
    }

    /** Блок «Для кого» (получатель и поздравление) на форме заказа; без записи настроек — показывается. */
    public function showsRecipient(): bool
    {
        return $this->order_show_recipient ?? true;
    }

    /** Галочка «Корпоративная покупка» (компания и БИН) на форме заказа. */
    public function corporateEnabled(): bool
    {
        return $this->order_corporate_enabled ?? true;
    }

    /** Идентификаторы попадают в код страницы — подключаются только в проверенном виде. */
    public function ga4(): ?string
    {
        return preg_match('/^G-[A-Z0-9]{4,15}$/', (string) $this->ga4_id) ? $this->ga4_id : null;
    }

    public function metrika(): ?string
    {
        return preg_match('/^\d{4,12}$/', (string) $this->metrika_id) ? $this->metrika_id : null;
    }

    /** Номер для wa.me: только цифры международного формата. */
    public function whatsapp(): ?string
    {
        $phone = PhoneNumber::normalize($this->whatsapp_phone);

        return $phone !== null && PhoneNumber::isValid($phone) ? ltrim($phone, '+') : null;
    }

    /** Оператор персональных данных (в политике конфиденциальности). */
    public function operator(): string
    {
        return filled($this->operator_name) ? trim((string) $this->operator_name) : self::DEFAULT_OPERATOR;
    }

    /** Адрес действующей публичной оферты; только https — чужую схему (javascript:, http:) на страницу не пускаем. */
    public function offerUrl(): ?string
    {
        $url = trim((string) $this->offer_url);

        return preg_match('#^https://[^\s"\'<>]+$#i', $url) && filter_var($url, FILTER_VALIDATE_URL) ? $url : null;
    }

    /** Текст на языке посетителя: перевод → русский → запасной текст по умолчанию (`$default` — уже переведённый `__()`). */
    public function text(string $key, string $default, ?string $lang = null): string
    {
        $text = LocalizedName::pick($this->text_translations[$key] ?? null, trim((string) $this->{$key}), $lang);

        return $text !== '' ? $text : $default;
    }

    /** Способы получения, доступные клиенту на сайте; пусто или мусор в настройках — все. @return list<DeliveryMethod> */
    public function deliveryMethods(): array
    {
        $active = array_values(array_filter(DeliveryMethod::cases(), fn (DeliveryMethod $m) => in_array($m->value, (array) $this->delivery_methods, true)));

        return $active ?: DeliveryMethod::cases();
    }

    public function hasAnalytics(): bool
    {
        return $this->ga4() !== null || $this->metrika() !== null;
    }

    public function accent(): string
    {
        return preg_match('/^#[0-9a-fA-F]{6}$/', (string) $this->accent_color) ? $this->accent_color : self::DEFAULT_ACCENT;
    }
}
