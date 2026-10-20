<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Оформление публичной страницы: главное фото, заголовок, акцентный цвет. Одна запись; пустое — значения по умолчанию. */
class ShopSetting extends Model
{
    public const DEFAULT_ACCENT = '#5F7872';

    protected $fillable = ['hero_image_path', 'hero_title', 'hero_subtitle', 'accent_color', 'updated_by'];

    public static function current(): self
    {
        return static::query()->first() ?? new static;
    }

    public function accent(): string
    {
        return preg_match('/^#[0-9a-fA-F]{6}$/', (string) $this->accent_color) ? $this->accent_color : self::DEFAULT_ACCENT;
    }
}
