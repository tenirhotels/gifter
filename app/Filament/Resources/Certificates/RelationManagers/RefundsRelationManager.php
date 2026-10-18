<?php

namespace App\Filament\Resources\Certificates\RelationManagers;

use App\Filament\Resources\Certificates\CertificateResource;
use App\Filament\Support\RefundActions;
use App\Filament\Support\RefundColumns;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class RefundsRelationManager extends RelationManager
{
    protected static string $relationship = 'refunds';

    protected static ?string $title = 'Возвраты';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord->refunds()->exists() && parent::canViewForRecord($ownerRecord, $pageClass);
    }

    public function table(Table $table): Table
    {
        return RefundColumns::apply($table)
            ->paginated(false)
            ->recordActions(RefundActions::make(fn () => $this->redirect(CertificateResource::getUrl('view', ['record' => $this->getOwnerRecord()]))));
    }

    public function isReadOnly(): bool
    {
        return false;
    }
}
