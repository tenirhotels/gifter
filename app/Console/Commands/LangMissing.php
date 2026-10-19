<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/** Русские подписи (`->label('…')`, `__('…')`), которых ещё нет в lang/kk.json и lang/en.json — список для перевода. */
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
        foreach (File::allFiles(app_path()) as $file) {
            if (preg_match_all("/(?:->label\\(|__\\()'([^'\\\\]*[А-Яа-яЁё][^'\\\\]*)'/u", File::get($file->getPathname()), $m)) {
                foreach ($m[1] as $text) {
                    $found[$text] = true;
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
