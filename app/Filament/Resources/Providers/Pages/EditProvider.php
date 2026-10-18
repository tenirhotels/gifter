<?php

namespace App\Filament\Resources\Providers\Pages;

use App\Filament\Resources\Providers\ProviderResource;
use App\Filament\Support\WithoutPageHeading;
use Filament\Resources\Pages\EditRecord;

class EditProvider extends EditRecord
{
    use WithoutPageHeading;

    protected static string $resource = ProviderResource::class;

    protected function getHeaderActions(): array
    {
        return [
        ];
    }
}
