<?php

namespace App\Filament\Resources\CommissionRules\Pages;

use App\Exceptions\DomainRuleViolation;
use App\Filament\Resources\CommissionRules\CommissionRuleResource;
use App\Services\CommissionManager;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateCommissionRule extends CreateRecord
{
    protected static string $resource = CommissionRuleResource::class;

    protected static bool $canCreateAnother = false;

    protected function handleRecordCreation(array $data): Model
    {
        try {
            return app(CommissionManager::class)->addRule($data);
        } catch (DomainRuleViolation $e) {
            Notification::make()->danger()->title($e->getMessage())->send();
            $this->halt(); // бросает Halt; throw — страховка и подсказка для статического анализа
            throw $e;
        }
    }
}
