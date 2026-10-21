<?php

namespace App\Filament\Resources\Services\Pages;

use App\Exceptions\DomainRuleViolation;
use App\Filament\Resources\Services\ServiceResource;
use App\Models\PmsService;
use App\Models\Service;
use App\Services\Pms\PmsCatalogSync;
use App\Services\ServicePricing;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Одна форма для услуги «из PMS» и «вручную»: выбор услуги из списка Exely подставляет название и привязывает ID;
 * цена и себестоимость вводятся здесь же (цену директора подтверждает администратор).
 */
class CreateService extends CreateRecord
{
    protected static string $resource = ServiceResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        try {
            return DB::transaction(function () use ($data) {
                $source = $data['source'] ?? 'manual';
                $pmsId = $data['pms_service_id'] ?? null;
                $price = $data['price'] ?? null;
                $cost = $data['cost'] ?? null;
                $validFrom = $data['valid_from'] ?? today();
                unset($data['source'], $data['pms_service_id'], $data['price'], $data['cost'], $data['valid_from']);

                $pms = null;
                if ($source === 'pms') {
                    $pms = PmsService::query()->where('external_id', (string) $pmsId)->lockForUpdate()->first();
                    if (! $pms || $pms->service_id || $pms->isMissing()) {
                        throw new DomainRuleViolation(__('Эта услуга из PMS недоступна: уже заведена в Gifter или пропала в PMS.'));
                    }
                }
                /** @var Service $service */
                $service = Service::create($data);
                $pricing = app(ServicePricing::class);
                if (filled($price)) {
                    $version = $pricing->addPrice($service, (string) $price, Carbon::parse($validFrom), null, filled($cost) ? (string) $cost : null);
                }
                if ($pms) {
                    $pms->update(['service_id' => $service->id]);
                    // Предлагать в сертификатах — только с утверждённой ценой (администратор утверждает сразу, директор — после подтверждения).
                    if (isset($version) && $version->isApproved()) {
                        app(PmsCatalogSync::class)->setAllowed($pms->refresh(), true);
                    }
                }

                return $service;
            });
        } catch (DomainRuleViolation $e) {
            Notification::make()->danger()->title($e->getMessage())->send();
            $this->halt(); // бросает Halt; throw — страховка и подсказка для статического анализа
            throw $e;
        }
    }
}
