<?php

namespace App\Filament\Resources\CertificateBatches\Pages;

use App\Exceptions\DomainRuleViolation;
use App\Filament\Resources\CertificateBatches\CertificateBatchResource;
use App\Models\Package;
use App\Services\CertificateBatchManager;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateCertificateBatch extends CreateRecord
{
    protected static string $resource = CertificateBatchResource::class;

    protected static ?string $title = 'Новый блок бесплатных сертификатов';

    protected function handleRecordCreation(array $data): Model
    {
        try {
            return app(CertificateBatchManager::class)->create(
                Package::findOrFail($data['package_id']),
                (int) $data['quantity'],
                $data['reason'],
                $data['purpose'],
                $data['customer_id'] ?? null,
            );
        } catch (DomainRuleViolation $e) {
            Notification::make()->danger()->title($e->getMessage())->send();
            $this->halt();
        }
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Блок создан — ждёт согласования директора';
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}
