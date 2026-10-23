<?php

namespace App\Services\Pms;

use App\Exceptions\DomainRuleViolation;
use App\Models\PmsService;
use App\Models\Service;
use App\Support\LocalizedName;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Список услуг из PMS (Exely). Ключ везде — ID услуги: название в PMS может быть на разных языках и меняться, дублей не будет.
 * Цен в справочнике Exely нет: услуга Gifter создаётся без цены (её вводит директор, подтверждает администратор).
 * Название услуги Gifter берётся при создании и дальше не перезаписывается (его можно переименовать или перевести).
 * Ничего не удаляется: пропавшее в PMS помечается, связанная услуга Gifter перестаёт предлагаться в новых сертификатах.
 */
class PmsCatalogSync
{
    public const CACHE_KEY = 'gifter.pms-catalog-sync';

    /** @return array{new: int, updated: int, missing: int, total: int, translated: int} */
    public function run(): array
    {
        try {
            $items = $this->load(PmsClient::make());
            $stats = DB::transaction(fn () => $this->apply($items));
        } catch (PmsException $e) {
            Cache::forever(self::CACHE_KEY, ['at' => now()->toIso8601String(), 'ok' => false, 'message' => $e->getMessage(), 'stats' => null]);
            throw $e;
        }
        Cache::forever(self::CACHE_KEY, ['at' => now()->toIso8601String(), 'ok' => true, 'message' => null, 'stats' => $stats]);

        return $stats;
    }

    /**
     * Справочник на трёх языках: русский обязателен (его сбой — сбой синхронизации), казахский и английский — по возможности.
     * Если на всех языках названия совпали, переводов у услуги нет (Exely язык не учёл) — они вводятся вручную.
     *
     * @return list<array{id: string, name: string, names: array<string, string>, kind: ?string, status: ?string}>
     */
    private function load(PmsClient $client): array
    {
        $items = [];
        foreach (['ru', 'kk', 'en'] as $lang) {
            try {
                $loaded = $client->catalogServices($lang);
            } catch (PmsException $e) {
                if ($lang === 'ru') {
                    throw $e;
                }
                Log::warning("PMS: названия услуг на языке {$lang} не загружены: ".$e->getMessage());

                continue;
            }
            foreach ($loaded as $item) {
                $id = $item['id'];
                $items[$id] ??= $item + ['by_lang' => []];
                $items[$id]['by_lang'] = $item['names'] + $items[$id]['by_lang'] + [$lang => $item['name']];
            }
        }

        return array_values(array_map(function (array $item) {
            $names = LocalizedName::distinct($item['by_lang']);
            unset($item['by_lang']);
            $item['names'] = $names;
            $item['name'] = $names['ru'] ?? $names['en'] ?? $names['kk'] ?? $item['name'];

            return $item;
        }, $items));
    }

    /** @return array{at: string, ok: bool, message: ?string, stats: ?array<string, int>}|null */
    public static function last(): ?array
    {
        return Cache::get(self::CACHE_KEY);
    }

    /** @param  list<array{id: string, name: string, names: array<string, string>, kind: ?string, status: ?string}>  $items
     * @return array{new: int, updated: int, missing: int, total: int, translated: int} */
    private function apply(array $items): array
    {
        $stats = ['new' => 0, 'updated' => 0, 'missing' => 0, 'total' => count($items), 'translated' => count(array_filter($items, fn (array $i) => $i['names'] !== []))];
        $seen = [];
        foreach ($items as $item) {
            $seen[] = $item['id'];
            $row = PmsService::query()->where('external_id', $item['id'])->first();
            if (! $row) {
                PmsService::create(['external_id' => $item['id'], 'name' => $item['name'], 'names' => $item['names'] ?: null, 'kind' => $item['kind'], 'status' => $item['status'], 'synced_at' => now()]);
                $stats['new']++;

                continue;
            }
            $wasMissing = $row->isMissing();
            $row->fill(['name' => $item['name'], 'names' => $item['names'] ?: $row->names, 'kind' => $item['kind'], 'status' => $item['status'], 'synced_at' => now(), 'missing_since' => null]);
            if ($row->isDirty(['name', 'names', 'kind', 'status']) || $wasMissing) {
                $stats['updated']++;
            }
            $row->save();
            if ($row->service && $row->names) {
                $this->fillTranslations($row->service, $row);
            }
            if ($wasMissing && $row->service && ! $row->service->is_active && $row->allowed_in_certificate) {
                $row->service->update(['is_active' => true]);
            }
        }
        // Пропавшее в PMS: помечаем, услугу Gifter скрываем из новых сертификатов (прежние сертификаты не меняются).
        PmsService::query()->whereNotIn('external_id', $seen ?: [''])->whereNull('missing_since')->get()->each(function (PmsService $row) use (&$stats) {
            $row->update(['missing_since' => now()]);
            $row->service?->update(['is_active' => false]);
            $stats['missing']++;
        });

        return $stats;
    }

    /** Пояснение к итогу синхронизации: удалось ли получить названия на нескольких языках. @param  array<string, int>  $stats */
    public static function languagesNote(array $stats): string
    {
        return ($stats['translated'] ?? 0) > 0
            ? __('названия на kk/ru/en: :p1', ['p1' => $stats['translated']])
            : __('Exely не отдаёт переводы названий — введите их вручную в услуге');
    }

    /** Переводы услуги Gifter из названий PMS (русское название — основное `name`). @return array<string, string>|null */
    private function serviceTranslations(PmsService $pms): ?array
    {
        return array_filter(Arr::only((array) $pms->names, ['kk', 'en'])) ?: null;
    }

    /** Дописывает переводы только в пустые языки: названия, которые директор поправил вручную, не затираются. */
    private function fillTranslations(Service $service, PmsService $pms): void
    {
        $current = (array) $service->name_translations;
        $merged = $current + (array) $this->serviceTranslations($pms);
        if ($merged !== $current) {
            $service->update(['name_translations' => $merged]);
        }
    }

    /** Тумблер «можно включать в сертификат»: включение создаёт услугу Gifter (без цены), выключение скрывает её из новых сертификатов. */
    public function setAllowed(PmsService $pms, bool $allowed): PmsService
    {
        return DB::transaction(function () use ($pms, $allowed) {
            $pms = PmsService::query()->lockForUpdate()->findOrFail($pms->id);
            if ($allowed && $pms->isMissing()) {
                throw new DomainRuleViolation(__('Услуги больше нет в PMS — включить её в сертификат нельзя.'));
            }
            if ($allowed && ! $pms->service_id) {
                $service = Service::create(['name' => $pms->name, 'name_translations' => $this->serviceTranslations($pms), 'unit' => __('шт'), 'is_active' => true]);
                $pms->service_id = $service->id;
            }
            if ($pms->service && $pms->names) {
                $this->fillTranslations($pms->service, $pms);
            }
            $pms->allowed_in_certificate = $allowed;
            $pms->save();
            $pms->service?->update(['is_active' => $allowed]);

            return $pms;
        });
    }

    /** Связать услугу PMS с уже существующей услугой Gifter (чтобы не создавать дубль). */
    public function link(PmsService $pms, Service $service): PmsService
    {
        if (PmsService::query()->where('service_id', $service->id)->whereKeyNot($pms->id)->exists()) {
            throw new DomainRuleViolation(__('Эта услуга Gifter уже связана с другой услугой PMS.'));
        }
        $pms->update(['service_id' => $service->id, 'allowed_in_certificate' => true]);
        $service->update(['is_active' => true]);

        return $pms;
    }
}
