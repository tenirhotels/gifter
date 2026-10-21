<?php

namespace App\Filament\Resources\Certificates\Pages;

use App\Exceptions\DomainRuleViolation;
use App\Filament\Resources\Certificates\CertificateResource;
use App\Filament\Support\WithoutPageHeading;
use App\Services\CertificateManager;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

/** Правка реквизитов — только для черновика. */
class EditCertificate extends EditRecord
{
    use WithoutPageHeading;

    protected static string $resource = CertificateResource::class;

    protected function getHeaderActions(): array
    {
        return [ViewAction::make()];
    }

    public function getRelationManagers(): array
    {
        return [];
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        try {
            return app(CertificateManager::class)->updateDraft($record, $data);
        } catch (DomainRuleViolation $e) {
            Notification::make()->danger()->title($e->getMessage())->send();
            $this->halt(); // бросает Halt; throw — страховка и подсказка для статического анализа
            throw $e;
        }
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}
