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

    /** Тумблер «Можно включать в сертификат» (у услуги из PMS) — через сервис синхронизации, вместе с признаком «Активна». */
    protected function afterSave(): void
    {
        $pms = $this->getRecord()->pmsService;
        $state = $this->form->getRawState();
        if (! $pms || ! auth()->user()?->can(Permission::ManageCatalog) || ! array_key_exists('pms_allowed', $state)) {
            return;
        }
        $wanted = (bool) $state['pms_allowed'] && (bool) ($state['is_active'] ?? true);
        if ($wanted === $pms->allowed_in_certificate) {
            return;
        }
        try {
            app(PmsCatalogSync::class)->setAllowed($pms, $wanted);
        } catch (DomainRuleViolation $e) {
            Notification::make()->danger()->title($e->getMessage())->send();
        }
    }
}
