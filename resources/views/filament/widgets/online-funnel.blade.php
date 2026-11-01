<x-filament-widgets::widget>
    <x-filament::section :heading="__('Воронка продаж с сайта')" :description="__('Продано с сайта: :p1', ['p1' => $funnel['amount']])">
        <x-slot name="afterHeader">
            <x-filament::input.wrapper>
                <x-filament::input.select wire:model.live="period" :aria-label="__('Период')">
                    @foreach ($periods as $key => $label)
                        <option value="{{ $key }}">{{ __($label) }}</option>
                    @endforeach
                </x-filament::input.select>
            </x-filament::input.wrapper>
        </x-slot>

        <div class="gf-funnel">
            @foreach ($funnel['steps'] as $step)
                <div class="gf-funnel-step">
                    <div class="gf-funnel-label">{{ $step['label'] }}</div>
                    <div class="gf-funnel-count">{{ $step['count'] }}</div>
                    <div class="gf-funnel-bar"><span style="width: {{ $step['percent'] ?? 0 }}%"></span></div>
                    <div class="gf-report-muted">{{ $step['percent'] === null ? '—' : $step['percent'].'%' }}</div>
                </div>
            @endforeach
        </div>

        @if (count($funnel['sources']) > 0)
            <div class="gf-report-muted" style="margin-top:1.25rem">{{ __('По источникам') }}</div>
            <table class="gf-table" style="width:100%;margin-top:.5rem">
                <thead><tr><th style="text-align:left">{{ __('Источник') }}</th><th style="text-align:right">{{ __('Заявки') }}</th><th style="text-align:right">{{ __('Оплачены') }}</th><th style="text-align:right">{{ __('Выпущены') }}</th></tr></thead>
                <tbody>
                    @foreach ($funnel['sources'] as $row)
                        <tr><td>{{ $row['source'] }}</td><td style="text-align:right">{{ $row['orders'] }}</td><td style="text-align:right">{{ $row['paid'] }}</td><td style="text-align:right">{{ $row['issued'] }}</td></tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
