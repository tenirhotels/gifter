<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/** Русские тексты (`->label('…')`, `__('…')`, плитки), которых ещё нет в lang/kk.json и lang/en.json — список для перевода. */
class LangMissing extends Command
{
    protected $signature = 'gifter:lang-missing {--lang=kk : kk или en}';

    protected $description = 'Показать подписи интерфейса без перевода';

    public function handle(): int
    {
        $lang = (string) $this->option('lang');
        $path = lang_path("{$lang}.json");
        if (! File::exists($path)) {
            $this->error("Нет файла lang/{$lang}.json");

            return self::FAILURE;
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
        }
        $missing = array_values(array_diff(array_keys($found), $have));
        sort($missing);
        foreach ($missing as $text) {
            $this->line($text);
        }
        $this->info(count($missing).' без перевода из '.count($found).' (lang/'.$lang.'.json).');

        return self::SUCCESS;
    }
}
