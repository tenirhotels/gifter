<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Полученное уведомление посредника (yespay): уникальный event_id — защита от повторной обработки. */
class YespayWebhookEvent extends Model
{
    protected $fillable = ['event_id', 'type', 'received_at', 'processed_at'];

    protected function casts(): array
    {
        return ['received_at' => 'datetime', 'processed_at' => 'datetime'];
    }
}
