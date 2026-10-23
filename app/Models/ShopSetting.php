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

    protected $fillable = ['hero_image_path', 'hero_title', 'hero_subtitle', 'hero_intro', 'contact_invite', 'text_translations', 'delivery_methods', 'accent_color', 'ga4_id', 'metrika_id', 'whatsapp_phone', 'operator_name', 'offer_url', 'updated_by'];

    protected function casts(): array
    {
        return ['text_translations' => 'array', 'delivery_methods' => 'array'];
    }

    public static function current(): self
    {
        return static::query()->first() ?? new static;
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
