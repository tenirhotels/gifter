<?php

namespace App\Models\Concerns;

use App\Support\ContactHash;
use App\Support\PhoneNumber;
use Illuminate\Database\Eloquent\Builder;

/**
 * Телефон, email и заметки хранятся в базе зашифрованными (APP_KEY): дамп базы или бэкап
 * без ключа их не раскрывает. Для поиска — хеши phone_hash и email_hash (точное совпадение).
 * В журнал аудита контакты не пишутся: только отметка, что они изменились.
 */
trait HasEncryptedContacts
{
    public const ENCRYPTED_CONTACTS = ['phone', 'email', 'notes'];

    public static function bootHasEncryptedContacts(): void
    {
        static::saving(function ($model) {
            if ($model->isDirty('phone')) {
                PhoneNumber::assertValid($model->phone);
                $model->phone = PhoneNumber::normalize($model->phone);
            }
            if ($model->isDirty('phone') || ! $model->exists) {
                $model->phone_hash = ContactHash::phone($model->phone);
            }
            if ($model->isDirty('email') || ! $model->exists) {
                $model->email_hash = ContactHash::email($model->email);
            }
        });
    }

    public function initializeHasEncryptedContacts(): void
    {
        $this->mergeCasts(array_fill_keys(self::ENCRYPTED_CONTACTS, 'encrypted'));
        $this->makeHidden(['phone_hash', 'email_hash']);
    }

    /** Поиск по телефону или email (точное значение); для прочего текста — ничего не находит. */
    public function scopeWhereContact(Builder $query, string $search): Builder
    {
        [$column, $hash] = ContactHash::forSearch($search) ?? [null, null];

        return $column && $hash ? $query->where($column, $hash) : $query->whereRaw('1 = 0');
    }

    /** @param array<string, mixed> $attributes */
    protected function maskContactsForAudit(array $attributes): array
    {
        foreach ([...self::ENCRYPTED_CONTACTS, 'phone_hash', 'email_hash'] as $key) {
            if (array_key_exists($key, $attributes)) {
                $attributes[$key] = $attributes[$key] === null ? null : '[скрыто]';
            }
        }

        return $attributes;
    }
}
