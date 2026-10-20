<?php

namespace App\Filament\Resources\OnlineOrders\Pages;

use App\Filament\Resources\OnlineOrders\OnlineOrderResource;
use Filament\Resources\Pages\ListRecords;

class ListOnlineOrders extends ListRecords
{
    protected static string $resource = OnlineOrderResource::class;
}
