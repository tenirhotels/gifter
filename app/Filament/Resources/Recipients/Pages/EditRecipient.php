<?php

namespace App\Filament\Resources\Recipients\Pages;

use App\Filament\Resources\Recipients\RecipientResource;
use App\Filament\Support\ContactFields;
use App\Filament\Support\WithoutPageHeading;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

/** В форме правки контакты видны полностью — открытие формы пишется в журнал аудита. */
class EditRecipient extends EditRecord
{
    use WithoutPageHeading;

    protected static string $resource = RecipientResource::class;

    protected function afterFill(): void
    {
        if (! ContactFields::logReveal($this->getRecord())) {
            Notification::make()->danger()->title(ContactFields::limitMessage())->send();
            $this->redirect(static::getResource()::getUrl('index'));
        }
    }
}
