<?php

namespace App\Filament\Resources\Recipients\Pages;

use App\Filament\Resources\Recipients\RecipientResource;
use App\Filament\Support\ContactFields;
use App\Filament\Support\WithoutPageHeading;
use Filament\Resources\Pages\EditRecord;

/** В форме правки контакты видны полностью — открытие формы пишется в журнал аудита. */
class EditRecipient extends EditRecord
{
    use WithoutPageHeading;

    protected static string $resource = RecipientResource::class;

    protected function afterFill(): void
    {
        ContactFields::logReveal($this->getRecord());
    }
}
