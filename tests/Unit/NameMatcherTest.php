<?php

namespace Tests\Unit;

use App\Support\NameMatcher;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class NameMatcherTest extends TestCase
{
    /** @return array<string, array{string, string, string}> */
    public static function same(): array
    {
        return [
            'кириллица и латиница' => ['Koichimanov Nurzhan', 'Койчиманов', 'Нуржан'],
            'порядок слов и регистр' => ['НУРЖАН КОЙЧИМАНОВ', 'Койчиманов', 'Нуржан'],
            'отчество не мешает' => ['Иванов Иван Петрович', 'Иванов', 'Иван'],
            'y вместо i, j вместо zh' => ['Nurjan Koychimanov', 'Койчиманов', 'Нуржан'],
            'казахские буквы' => ['Kenzhegali Aigerim', 'Кенжеғали', 'Әйгерім'],
            'kh / h' => ['Khasenov Ruslan', 'Хасенов', 'Руслан'],
            'удвоенные буквы' => ['Yuliya Sergeeva', 'Сергеева', 'Юлия'],
            'двойная фамилия' => ['Petrova-Vodkina Anna', 'Петрова-Водкина', 'Анна'],
            'ё и е' => ['Семёнов Пётр', 'Семенов', 'Петр'],
            'Ye в начале имени' => ['Yerlan Sarsenov', 'Сарсенов', 'Ерлан'],
        ];
    }

    #[DataProvider('same')]
    public function test_same_person(string $booking, string $last, string $first): void
    {
        $this->assertTrue(NameMatcher::matches($booking, $last, $first), $booking);
    }

    public function test_different_people(): void
    {
        $this->assertFalse(NameMatcher::matches('Петров Иван', 'Иванов', 'Иван'));
        $this->assertFalse(NameMatcher::matches('Иванов', 'Иванов', 'Иван'), 'Нужны и фамилия, и имя');
        $this->assertFalse(NameMatcher::matches('Иванов Иван', 'Иванов', null));
        $this->assertFalse(NameMatcher::matches('', 'Иванов', 'Иван'));
    }
}
