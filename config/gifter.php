<?php

return [
    'currency' => 'KZT',

    // Срок действия нового сертификата по умолчанию (дней от даты продажи).
    'validity_days' => (int) env('GIFTER_VALIDITY_DAYS', 365),

    'number' => [
        // Шаблон номера: {PREFIX}, {YYYY}, {YY}, {SEQ}. Если в шаблоне есть год,
        // нумерация начинается заново каждый год (по дате продажи).
        'pattern' => env('GIFTER_NUMBER_PATTERN', '{PREFIX}-{YYYY}-{SEQ}'),
        'prefix' => env('GIFTER_NUMBER_PREFIX', 'GIF'),
        'padding' => (int) env('GIFTER_NUMBER_PADDING', 6),
    ],
];
