<?php

namespace App\Filament\Resources\Services\Pages;

use App\Filament\Resources\Services\ServiceResource;
use App\Filament\Support\WithoutPageHeading;
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
}
