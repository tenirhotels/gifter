<?php

namespace App\Filament\Resources\OnlineOrders\Pages;

use App\Filament\Resources\OnlineOrders\OnlineOrderResource;
use App\Filament\Resources\OnlineOrders\Widgets\OnlineFunnelWidget;
use Filament\Resources\Pages\ListRecords;

class ListOnlineOrders extends ListRecords
{
    protected static string $resource = OnlineOrderResource::class;

    protected function getHeaderWidgets(): array
    {
        return [OnlineFunnelWidget::class];
    }
}
