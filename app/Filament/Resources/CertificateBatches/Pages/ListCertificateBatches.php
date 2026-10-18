<?php

namespace App\Filament\Resources\CertificateBatches\Pages;

use App\Filament\Resources\CertificateBatches\CertificateBatchResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCertificateBatches extends ListRecords
{
    protected static string $resource = CertificateBatchResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Новый блок')];
    }
}
