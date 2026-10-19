<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Подключение к PMS: адрес хаба (или Exely), код отеля и токен. Одна запись. Токен хранится зашифрованным
 * и в панели не показывается. Интеграция выключена, пока нет адреса, кода и токена.
 */
class PmsSetting extends Model
{
    protected $fillable = ['enabled', 'base_url', 'property_code', 'token', 'updated_by'];

    protected $hidden = ['token'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'token' => 'encrypted'];
    }

    public static function current(): self
    {
        return static::query()->first() ?? new static;
    }

    /** Подключение включено и заполнено — брони проверяются по PMS строго. */
    public function isActive(): bool
    {
        return $this->enabled && filled($this->base_url) && filled($this->property_code) && filled($this->token);
    }
}
