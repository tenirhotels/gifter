<div class="gf-report-scroll">
    @if ($rows->isEmpty())
        <p class="gf-report-muted">{{ __('За выбранный период данных нет') }}</p>
    @else
        <table class="gf-report">
            <thead>
                <tr>
                    <th>{{ __('Сертификат') }}</th>
                    <th>{{ __('Дата продажи') }}</th>
                    <th>{{ __('Заказчик') }}</th>
                    <th>{{ __('Статус сертификата') }}</th>
                    <th>{{ __('Дата использования') }}</th>
                    <th class="gf-num">{{ __('Сумма продажи, ₸') }}</th>
                    <th>{{ __('Комиссия') }}</th>
                    <th class="gf-num">{{ __('Комиссия, ₸') }}</th>
                    <th>{{ __('Дата выплаты') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($rows as $r)
                    <tr>
                        <td><strong>{{ $r['number'] }}</strong><br><span class="gf-report-muted">{{ $r['package'] }}</span></td>
                        <td>{{ $r['sold_at']?->format('d.m.Y') }}</td>
                        <td>{{ $r['customer'] ?? '—' }}</td>
                        <td>{{ $r['status'] }}</td>
                        <td>{{ $r['used_at']?->format('d.m.Y') ?? '—' }}</td>
                        <td class="gf-num">{{ \App\Support\Money::format(\App\Support\Money::fromMinor($r['sale_minor'])) }}</td>
                        <td>{{ $r['commission_status'] ?? '—' }}</td>
                        <td class="gf-num">{{ \App\Support\Money::format(\App\Support\Money::fromMinor($r['commission_minor'])) }}</td>
                        <td>{{ $r['paid_at']?->format('d.m.Y') ?? '—' }}@if ($r['reference'])<br><span class="gf-report-muted">{{ $r['reference'] }}</span>@endif</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</div>
