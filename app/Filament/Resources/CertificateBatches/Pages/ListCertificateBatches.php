<?php

namespace App\Filament\Resources\CertificateBatches\Pages;

use App\Filament\Resources\CertificateBatches\CertificateBatchResource;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\Support\Htmlable;

/** Список групп: без крупного заголовка — раздел понятен из хлебных крошек. */
class ListCertificateBatches extends ListRecords
{
    protected static string $resource = CertificateBatchResource::class;

    public function getHeading(): string|Htmlable
    {
        return '';
    }
}
