<x-filament-panels::page>
    {{ $this->form }}

    <x-filament::section :heading="__('Сводка')" :description="$periodLabel">
        @if ($summary->isEmpty())
            <p class="gf-report-muted">{{ __('За выбранный период данных нет') }}</p>
        @else
            <div class="gf-report-scroll">
                <table class="gf-report">
                    <thead>
                        <tr>
                            <th>{{ __('Продавец') }}</th>
                            <th class="gf-num">{{ __('Сертификатов') }}</th>
                            <th class="gf-num">{{ __('Сумма продаж') }}</th>
                            <th class="gf-num">{{ __('Использовано') }}</th>
                            <th class="gf-num">{{ __('Не использовано') }}</th>
                            <th class="gf-num">{{ __('Без брони') }}</th>
                            <th class="gf-num">{{ __('Начислено') }}</th>
                            <th class="gf-num">{{ __('К согласованию') }}</th>
                            <th class="gf-num">{{ __('К выплате') }}</th>
                            <th class="gf-num">{{ __('Выплачено') }}</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($summary as $row)
                            <tr>
                                <td><strong>{{ $row['seller'] }}</strong></td>
                                <td class="gf-num">{{ $row['count'] }}</td>
                                <td class="gf-num">{{ $money($row['sales_minor']) }}</td>
                                <td class="gf-num">{{ $row['used'] }}</td>
                                <td class="gf-num">{{ $row['not_used'] }}@if ($row['expired'])<br><span class="gf-report-muted">{{ __('истекло: :p1', ['p1' => $row['expired']]) }}</span>@endif</td>
                                <td class="gf-num">{{ $row['no_reservation'] }}</td>
                                <td class="gf-num">{{ $money($row['earned_minor']) }}</td>
                                <td class="gf-num">{{ $money($row['to_approve_minor']) }}</td>
                                <td class="gf-num">{{ $money($row['approved_minor']) }}</td>
                                <td class="gf-num">{{ $money($row['paid_minor']) }}</td>
                                <td class="gf-report-links">
                                    <a href="{{ \App\Filament\Pages\SellerCommissions::getUrl(['seller' => $row['seller_id']]) }}">{{ __('Согласование и выплаты') }}</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr>
                            <td>{{ __('Итого') }}</td>
                            <td class="gf-num">{{ $totals['count'] }}</td>
                            <td class="gf-num">{{ $totals['sales'] }}</td>
                            <td class="gf-num">{{ $totals['used'] }}</td>
                            <td class="gf-num">{{ $totals['not_used'] }}</td>
                            <td class="gf-num">{{ $totals['no_reservation'] }}</td>
                            <td class="gf-num">{{ $totals['earned'] }}</td>
                            <td class="gf-num">{{ $totals['to_approve'] }}</td>
                            <td class="gf-num">{{ $totals['approved'] }}</td>
                            <td class="gf-num">{{ $totals['paid'] }}</td>
                            <td></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
            <p class="gf-report-muted">{{ __('Список сертификатов с датами, комиссией и выплатами — в выгрузке Excel.') }}</p>
        @endif
    </x-filament::section>

    @if (count($months['months']) > 0)
        <x-filament::section :heading="__('Начисленная комиссия по месяцам')">
            <div class="gf-report-scroll">
                <table class="gf-report">
                    <thead>
                        <tr>
                            <th>{{ __('Продавец') }}</th>
                            @foreach ($months['months'] as $month)
                                <th class="gf-num">{{ \Illuminate\Support\Carbon::createFromFormat('Y-m', $month)->translatedFormat('M Y') }}</th>
                            @endforeach
                            <th class="gf-num">{{ __('Итого') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($months['rows'] as $row)
                            <tr>
                                <td><strong>{{ $row['seller'] }}</strong></td>
                                @foreach ($row['values'] as $minor)
                                    <td class="gf-num">{{ $money($minor) }}</td>
                                @endforeach
                                <td class="gf-num"><strong>{{ $money($row['total']) }}</strong></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-filament::section>
    @endif
</x-filament-panels::page>
