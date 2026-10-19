<?php

namespace App\Services\Pms;

use App\Exceptions\DomainRuleViolation;
use App\Models\PmsService;
use App\Models\Service;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Список услуг из PMS (Exely). Ключ везде — ID услуги: название в PMS может быть на разных языках и меняться, дублей не будет.
 * Цен в справочнике Exely нет: услуга Gifter создаётся без цены (её вводит директор, подтверждает администратор).
 * Название услуги Gifter берётся при создании и дальше не перезаписывается (его можно переименовать или перевести).
 * Ничего не удаляется: пропавшее в PMS помечается, связанная услуга Gifter перестаёт предлагаться в новых сертификатах.
 */
class PmsCatalogSync
{
    public const CACHE_KEY = 'gifter.pms-catalog-sync';

    /** @return array{new: int, updated: int, missing: int, total: int} */
    public function run(): array
    {
        try {
            $items = PmsClient::make()->catalogServices();
            $stats = DB::transaction(fn () => $this->apply($items));
        } catch (PmsException $e) {
            Cache::forever(self::CACHE_KEY, ['at' => now()->toIso8601String(), 'ok' => false, 'message' => $e->getMessage(), 'stats' => null]);
            throw $e;
        }
        Cache::forever(self::CACHE_KEY, ['at' => now()->toIso8601String(), 'ok' => true, 'message' => null, 'stats' => $stats]);

        return $stats;
    }

    /** @return array{at: string, ok: bool, message: ?string, stats: ?array<string, int>}|null */
    public static function last(): ?array
    {
        return Cache::get(self::CACHE_KEY);
    }

    /** @param  list<array{id: string, name: string, kind: ?string, status: ?string}>  $items
     * @return array{new: int, updated: int, missing: int, total: int} */
    private function apply(array $items): array
    {
        $stats = ['new' => 0, 'updated' => 0, 'missing' => 0, 'total' => count($items)];
        $seen = [];
        foreach ($items as $item) {
            $seen[] = $item['id'];
            $row = PmsService::query()->where('external_id', $item['id'])->first();
            if (! $row) {
                PmsService::create(['external_id' => $item['id'], 'name' => $item['name'], 'kind' => $item['kind'], 'status' => $item['status'], 'synced_at' => now()]);
                $stats['new']++;

                continue;
            }
            $wasMissing = $row->isMissing();
            $row->fill(['name' => $item['name'], 'kind' => $item['kind'], 'status' => $item['status'], 'synced_at' => now(), 'missing_since' => null]);
            if ($row->isDirty(['name', 'kind', 'status']) || $wasMissing) {
                $stats['updated']++;
            }
            $row->save();
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

    /** Тумблер «можно включать в сертификат»: включение создаёт услугу Gifter (без цены), выключение скрывает её из новых сертификатов. */
    public function setAllowed(PmsService $pms, bool $allowed): PmsService
    {
        return DB::transaction(function () use ($pms, $allowed) {
            $pms = PmsService::query()->lockForUpdate()->findOrFail($pms->id);
            if ($allowed && $pms->isMissing()) {
                throw new DomainRuleViolation('Услуги больше нет в PMS — включить её в сертификат нельзя.');
            }
            if ($allowed && ! $pms->service_id) {
                $service = Service::create(['name' => $pms->name, 'unit' => 'шт', 'is_active' => true]);
                $pms->service_id = $service->id;
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
            throw new DomainRuleViolation('Эта услуга Gifter уже связана с другой услугой PMS.');
        }
        $pms->update(['service_id' => $service->id, 'allowed_in_certificate' => true]);
        $service->update(['is_active' => true]);

        return $pms;
    }
}
