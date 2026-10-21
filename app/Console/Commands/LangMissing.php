<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/** Русские тексты (`->label('…')`, `__('…')`, плитки), которых ещё нет в lang/kk.json и lang/en.json — список для перевода. */
class LangMissing extends Command
{
    protected $signature = 'gifter:lang-missing {--lang=kk : kk или en} {--unused : показать ключи, которых нет в коде} {--prune : удалить такие ключи из kk.json и en.json}';

    protected $description = 'Показать подписи интерфейса без перевода';

    private const TILE_CALL = '/\$this->(?:counter|metric|commissionTile)(\((?:[^()\']++|\'(?:[^\'\\\\]|\\\\.)*\'|(?1))*\))/u';

    private const CYRILLIC_LITERAL = '/\'((?:[^\'\\\\]|\\\\.)*[А-Яа-яЁё](?:[^\'\\\\]|\\\\.)*)\'/u';

    public function handle(): int
    {
        $lang = (string) $this->option('lang');
        $path = lang_path("{$lang}.json");
        if (! File::exists($path)) {
            $this->error("Нет файла lang/{$lang}.json");

            return self::FAILURE;
        }
        if ($this->option('unused') || $this->option('prune')) {
            return $this->unused();
        }
        $have = array_keys(json_decode(File::get($path), true) ?? []);
        $found = [];
        $pattern = "/(?:->label\\(|__\\(|counter\\(|metric\\(|commissionTile\\('[a-z_]+', )'((?:[^'\\\\]|\\\\.)*[А-Яа-яЁё](?:[^'\\\\]|\\\\.)*)'/u";
        foreach ([...File::allFiles(app_path()), ...File::allFiles(resource_path('views'))] as $file) {
            if (preg_match_all($pattern, File::get($file->getPathname()), $m)) {
                foreach ($m[1] as $text) {
                    $found[stripcslashes($text)] = true;
                }
            }
            // В плитках переводятся и подсказки (не только первый аргумент): все русские строки внутри вызова.
            if (preg_match_all(self::TILE_CALL, File::get($file->getPathname()), $calls)) {
                foreach ($calls[0] as $call) {
                    preg_match_all(self::CYRILLIC_LITERAL, $call, $m);
                    foreach ($m[1] as $text) {
                        $found[stripcslashes($text)] = true;
                    }
                }
            }
        }
        $missing = array_values(array_diff(array_keys($found), $have));
        sort($missing);
        foreach ($missing as $text) {
            $this->line($text);
        }
        $this->info(count($missing).' без перевода из '.count($found).' (lang/'.$lang.'.json).');

        return self::SUCCESS;
    }

    /**
     * Ключи переводов, текст которых нигде в коде не встречается (ни в app, ни в resources, config, routes, database).
     * Проверка мягкая — по вхождению текста в файл, чтобы не удалить ключ, который собирается динамически.
     */
    private function unused(): int
    {
        $code = '';
        foreach ([app_path(), resource_path('views'), config_path(), base_path('routes'), database_path()] as $dir) {
            foreach (File::allFiles($dir) as $file) {
                if (in_array($file->getExtension(), ['php'], true)) {
                    $code .= File::get($file->getPathname())."\n";
                }
            }
        }
        $code = str_replace(["\\'", '\\"'], ["'", '"'], $code);

        $files = ['kk', 'en'];
        $keys = array_keys(json_decode(File::get(lang_path('kk.json')), true) ?? []);
        $unused = array_values(array_filter($keys, fn (string $key) => ! str_contains($code, $key)));
        foreach ($unused as $key) {
            $this->line($key);
        }

        if ($this->option('prune') && $unused !== []) {
            foreach ($files as $lang) {
                $data = json_decode(File::get(lang_path("{$lang}.json")), true) ?? [];
                $data = array_diff_key($data, array_flip($unused));
                ksort($data);
                // Формат файлов — отступ в один пробел (как в исходных), чтобы diff показывал только удалённые ключи.
                $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                File::put(lang_path("{$lang}.json"), preg_replace_callback('/^( {4})+/m', fn (array $m) => str_repeat(' ', intdiv(strlen($m[0]), 4)), $json)."\n");
            }
            $this->info('Удалено ключей: '.count($unused).' из kk.json и en.json.');

            return self::SUCCESS;
        }
        $this->info(count($unused).' ключей без использования в коде (из '.count($keys).').');

        return self::SUCCESS;
    }
}
