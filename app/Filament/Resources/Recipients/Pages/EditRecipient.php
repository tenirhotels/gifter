<?php

namespace App\Filament\Resources\Recipients\Pages;

use App\Filament\Resources\Recipients\RecipientResource;
use Filament\Resources\Pages\EditRecord;

class EditRecipient extends EditRecord
{
    protected static string $resource = RecipientResource::class;

    protected function getHeaderActions(): array
    {
        return [
        ];
    }
}
