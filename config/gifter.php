<?php

return [
    'currency' => 'KZT',

    // Срок действия задаётся в пакете (продукте), а не здесь.

    'number' => [
        // Шаблон номера: {PREFIX}, {YYYY}, {YY}, {SEQ}. Если в шаблоне есть год,
        // нумерация начинается заново каждый год (по дате продажи).
        'pattern' => env('GIFTER_NUMBER_PATTERN', '{PREFIX}-{SEQ}'),
        'prefix' => env('GIFTER_NUMBER_PREFIX', 'GIF'),
        'padding' => (int) env('GIFTER_NUMBER_PADDING', 6),
    ],
];
