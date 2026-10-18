<?php

namespace App\Filament\Resources\Certificates\RelationManagers;

use App\Filament\Resources\Certificates\CertificateResource;
use App\Filament\Support\FulfillmentActions;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/** Заявки на исполнение услуг сертификата. Появляются при отметке брони. */
class FulfillmentsRelationManager extends RelationManager
{
    protected static string $relationship = 'fulfillments';

    protected static ?string $title = 'Исполнение';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord->items()->where('requires_fulfillment', true)->exists() && parent::canViewForRecord($ownerRecord, $pageClass);
    }

    public function table(Table $table): Table
    {
        return $table
            ->description('Подготовка услуг, которым нужна заявка поставщику. Заявки появляются при отметке брони. Выполнение услуги — не погашение: сертификат гасится только целиком.')
            ->modifyQueryUsing(fn ($query) => $query->with(['item', 'provider', 'certificate.recipient', 'updater']))
            ->columns(FulfillmentActions::columns())
            ->recordActions(FulfillmentActions::make(fn () => $this->redirect(CertificateResource::getUrl('view', ['record' => $this->getOwnerRecord()]))))
            ->emptyStateHeading('Заявок пока нет')
            ->emptyStateDescription('Они появятся, когда Front Office отметит бронь.')
            ->paginated(false)
            ->defaultSort('id');
    }

    public function isReadOnly(): bool
    {
        return false;
    }
}
