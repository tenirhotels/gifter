<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Подключение к PMS: адрес хаба (или Exely), код отеля и токен. Одна запись. Токен хранится зашифрованным
 * и в панели не показывается. Интеграция выключена, пока нет адреса, кода и токена.
 */
class PmsSetting extends Model
{
    public const HUB = 'hub';

    public const EXELY = 'exely';

    public const EXELY_DEFAULT_URL = 'https://connect.hopenapi.com';

    protected $fillable = ['enabled', 'provider', 'base_url', 'property_code', 'certificate_rate_plan', 'token', 'client_id', 'client_secret', 'updated_by'];

    protected $hidden = ['token', 'client_secret'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'token' => 'encrypted', 'client_secret' => 'encrypted'];
    }

    public function isExely(): bool
    {
        return $this->provider === self::EXELY;
    }

    /** Адрес API: у Exely по умолчанию connect.hopenapi.com. */
    public function baseUrl(): string
    {
        return rtrim((string) ($this->base_url ?: ($this->isExely() ? self::EXELY_DEFAULT_URL : '')), '/');
    }

    public static function current(): self
    {
        return static::query()->first() ?? new static;
    }

    /** Подключение включено и заполнено выбранным источником — брони проверяются по PMS строго. */
    public function isActive(): bool
    {
        if (! $this->enabled || blank($this->property_code)) {
            return false;
        }

        return $this->isExely()
            ? filled($this->client_id) && filled($this->client_secret)
            : filled($this->base_url) && filled($this->token);
    }
}
