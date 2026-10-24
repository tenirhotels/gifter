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
            if ($model->hasSecondPhone()) {
                if ($model->isDirty('phone2')) {
                    PhoneNumber::assertValid($model->phone2);
                    $model->phone2 = PhoneNumber::normalize($model->phone2);
                }
                // Второй номер, совпадающий с первым, не хранится.
                if (filled($model->phone2) && ContactHash::phone($model->phone2) === ContactHash::phone($model->phone)) {
                    $model->phone2 = null;
                }
                if ($model->isDirty('phone2') || $model->isDirty('phone') || ! $model->exists) {
                    $model->phone2_hash = ContactHash::phone($model->phone2);
                }
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
        if ($this->hasSecondPhone()) {
            $this->mergeCasts(['phone2' => 'encrypted']);
            $this->makeHidden(['phone2_hash']);
        }
    }

    /** Второй телефон есть у заказчика и получателя (поле phone2 в fillable). */
    public function hasSecondPhone(): bool
    {
        return in_array('phone2', $this->getFillable(), true);
    }

    /** Поиск по телефону или email (точное значение); для прочего текста — ничего не находит. */
    public function scopeWhereContact(Builder $query, string $search): Builder
    {
        [$column, $hash] = ContactHash::forSearch($search) ?? [null, null];

        if (! $column || ! $hash) {
            return $query->whereRaw('1 = 0');
        }
        if ($column === 'phone_hash' && $this->hasSecondPhone()) {
            return $query->where(fn (Builder $q) => $q->where('phone_hash', $hash)->orWhere('phone2_hash', $hash));
        }

        return $query->where($column, $hash);
    }

    /** @param array<string, mixed> $attributes */
    protected function maskContactsForAudit(array $attributes): array
    {
        foreach ([...self::ENCRYPTED_CONTACTS, 'phone2', 'phone_hash', 'phone2_hash', 'email_hash'] as $key) {
            if (array_key_exists($key, $attributes)) {
                $attributes[$key] = $attributes[$key] === null ? null : __('[скрыто]');
            }
        }

        return $attributes;
    }
}
