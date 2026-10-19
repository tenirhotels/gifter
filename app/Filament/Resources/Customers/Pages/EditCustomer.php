<?php

namespace App\Filament\Resources\Customers\Pages;

use App\Filament\Resources\Customers\CustomerResource;
use App\Filament\Support\ContactFields;
use App\Filament\Support\WithoutPageHeading;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

/** В форме правки контакты видны полностью — открытие формы пишется в журнал аудита. */
class EditCustomer extends EditRecord
{
    use WithoutPageHeading;

    protected static string $resource = CustomerResource::class;

    protected function afterFill(): void
    {
        if (! ContactFields::logReveal($this->getRecord())) {
            Notification::make()->danger()->title(ContactFields::limitMessage())->send();
            $this->redirect(static::getResource()::getUrl('index'));
        }
    }
}
