<?php

namespace App\Filament\Resources\Certificates\Pages;

use App\Exceptions\DomainRuleViolation;
use App\Filament\Resources\Certificates\CertificateResource;
use App\Services\CertificateManager;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateCertificate extends CreateRecord
{
    protected static string $resource = CertificateResource::class;

    protected static ?string $title = 'Новый сертификат';

    protected function handleRecordCreation(array $data): Model
    {
        try {
            return app(CertificateManager::class)->createDraft($data);
        } catch (DomainRuleViolation $e) {
            Notification::make()->danger()->title($e->getMessage())->send();
            $this->halt();
        }
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Черновик создан';
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}
