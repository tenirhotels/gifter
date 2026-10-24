<?php

namespace App\Support;

/**
 * Слова для номеров сертификатов: три латинские буквы, казахские слова о природе (латиница 2021: Q, J).
 * Список только дополняется в конец (до 100 слов), чтобы уже выданные номера оставались понятными.
 * Чем больше слов, тем больше вариантов номера: N слов дают N·(N−1) пар, умноженных на 10 000 цифр.
 */
final class NumberWords
{
    public const LIST = [
        'TAU', 'TAS', 'KUN', 'JEL', 'KOL', 'KOK', 'QAR', 'QUM', 'ARU', 'ASU', 'SAY', 'MUZ', 'TUN', 'NUR',
        'TAN', 'QUZ', 'JER', 'TAL', 'AYU', 'BAL', 'JAZ', 'QYS', 'KUZ', 'BAQ', 'ARA', 'ALA', 'UYA',
        'GUL', 'QUS', 'JOL', 'JAS', 'NAZ', 'KUY', 'JAN', 'TOY',
    ];
}
