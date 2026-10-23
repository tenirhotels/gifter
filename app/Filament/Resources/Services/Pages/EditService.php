<?php

namespace App\Filament\Resources\Services\Pages;

use App\Enums\Permission;
use App\Exceptions\DomainRuleViolation;
use App\Filament\Resources\Services\ServiceResource;
use App\Filament\Support\WithoutPageHeading;
use App\Services\Pms\PmsCatalogSync;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditService extends EditRecord
{
    use WithoutPageHeading;

    protected static string $resource = ServiceResource::class;

    protected function getHeaderActions(): array
    {
        return [
        ];
    }

    /** Тумблер «Можно включать в сертификат» (= «Активна») у услуги из PMS синхронизируется с её флагом в списке PMS. */
    protected function afterSave(): void
    {
        $service = $this->getRecord();
        $pms = $service->pmsService;
        if (! $pms || ! auth()->user()?->can(Permission::ManageCatalog)) {
            return;
        }
        $wanted = (bool) $service->is_active;
        if ($wanted === $pms->allowed_in_certificate) {
            return;
        }
        try {
            app(PmsCatalogSync::class)->setAllowed($pms, $wanted);
        } catch (DomainRuleViolation $e) {
            $service->update(['is_active' => false]);
            Notification::make()->danger()->title($e->getMessage())->send();
        }
    }
}
