<?php

namespace App\Filament\Resources\OnlineOrders\Widgets;

use App\Services\OnlineFunnel;
use Filament\Widgets\Widget;

/** Воронка заявок с сайта на странице «Заявки с сайта». */
class OnlineFunnelWidget extends Widget
{
    protected string $view = 'filament.widgets.online-funnel';

    protected int|string|array $columnSpan = 'full';

    public string $period = 'this';

    protected function getViewData(): array
    {
        return ['funnel' => app(OnlineFunnel::class)->build($this->period), 'periods' => OnlineFunnel::PERIODS];
    }
}
