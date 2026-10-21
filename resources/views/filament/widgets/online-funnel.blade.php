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
    </x-filament::section>
</x-filament-widgets::widget>
