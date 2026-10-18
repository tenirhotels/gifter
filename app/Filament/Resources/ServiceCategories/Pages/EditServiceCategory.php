<?php

namespace App\Filament\Resources\ServiceCategories\Pages;

use App\Filament\Resources\ServiceCategories\ServiceCategoryResource;
use App\Filament\Support\WithoutPageHeading;
use Filament\Resources\Pages\EditRecord;

class EditServiceCategory extends EditRecord
{
    use WithoutPageHeading;

    protected static string $resource = ServiceCategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [
        ];
    }
}
