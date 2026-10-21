<?php

namespace App\Filament\Resources\Users\Pages;

use App\Exceptions\DomainRuleViolation;
use App\Filament\Resources\Users\UserResource;
use App\Filament\Support\WithoutPageHeading;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditUser extends EditRecord
{
    use WithoutPageHeading;

    protected static string $resource = UserResource::class;

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        try {
            return parent::handleRecordUpdate($record, $data);
        } catch (DomainRuleViolation $e) {
            Notification::make()->danger()->title($e->getMessage())->send();
            $this->halt(); // бросает Halt; throw — страховка и подсказка для статического анализа
            throw $e;
        }
    }
}
