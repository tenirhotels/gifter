<?php

namespace App\Filament\Resources\Fulfillments\Pages;

use App\Filament\Resources\Fulfillments\ServiceFulfillmentResource;
use Filament\Resources\Pages\ListRecords;

class ListServiceFulfillments extends ListRecords
{
    protected static string $resource = ServiceFulfillmentResource::class;

    protected static ?string $title = 'Исполнение услуг';
}
