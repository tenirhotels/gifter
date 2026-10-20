<?php

namespace Tests\Feature;

use Tests\TestCase;

/** Тексты интерфейса не выходят за размеры макета (docs/ui-writing.md). */
class UiTextLimitsTest extends TestCase
{
    /** @return list<array{string, string, int}> */
    private function rules(): array
    {
        return [
            ['колонка', "/(?:TextColumn|IconColumn)::make\('[^']+'\)(?:->[a-zA-Z]+\((?:[^()]|\([^()]*\))*\))*?->label\('((?:[^'\\\\]|\\\\.)*)'\)/", 20],
            ['кнопка', "/Action::make\('[^']+'\)->label\('((?:[^'\\\\]|\\\\.)*)'\)/", 28],
            ['пункт меню', "/navigationLabel = '((?:[^'\\\\]|\\\\.)*)'/", 24],
            ['подпись', "/->label\('((?:[^'\\\\]|\\\\.)*)'\)/", 54],
            ['подсказка', "/->helperText\('((?:[^'\\\\]|\\\\.)*)'\)/", 120],
            ['описание окна', "/->modalDescription\('((?:[^'\\\\]|\\\\.)*)'\)/", 200],
            ['название плитки', "/counter\('((?:[^'\\\\]|\\\\.)*)'/", 28],
        ];
    }

    public function test_texts_fit_their_elements(): void
    {
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path('Filament')));
        $violations = [];
        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            foreach (file($file->getPathname()) as $i => $line) {
                foreach ($this->rules() as [$kind, $pattern, $limit]) {
                    if (preg_match_all($pattern, $line, $m)) {
                        foreach ($m[1] as $text) {
                            if (mb_strlen($text) > $limit) {
                                $violations[] = "{$kind} ({$limit}): ".$file->getFilename().':'.($i + 1).' — '.$text;
                            }
                        }
                    }
                }
            }
        }

        $this->assertSame([], $violations, implode("\n", $violations));
    }
}
