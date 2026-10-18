<?php

namespace App\Filament\Resources\Packages\Pages;

use App\Filament\Resources\Packages\PackageResource;
use App\Filament\Support\WithoutPageHeading;
use Filament\Resources\Pages\EditRecord;

class EditPackage extends EditRecord
{
    use WithoutPageHeading;

    protected static string $resource = PackageResource::class;

    protected function getHeaderActions(): array
    {
        return [
        ];
    }
}
