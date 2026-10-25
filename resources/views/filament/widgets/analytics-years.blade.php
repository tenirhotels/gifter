@php
    $money = fn ($v) => \App\Support\Money::format(number_format((float) $v, 2, '.', ''));
@endphp
<x-filament-widgets::widget>
    <x-filament::section :heading="__('По годам')">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-gray-500">
                        <th class="py-2 pr-4">{{ __('Год') }}</th>
                        <th class="py-2 pr-4 text-right">{{ __('Продано') }}</th>
                        <th class="py-2 pr-4 text-right">{{ __('Оплачено') }}</th>
                        <th class="py-2 pr-4 text-right">{{ __('Использовано') }}</th>
                        <th class="py-2 pr-4 text-right">{{ __('Бесплатных') }}</th>
                        <th class="py-2 text-right">{{ __('Расход на бесплатные') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($report['labels'] as $i => $label)
                        <tr class="border-t border-gray-200 dark:border-white/10">
                            <td class="py-2 pr-4 font-medium">{{ $label }}</td>
                            <td class="py-2 pr-4 text-right">{{ $money($report['sold'][$i]) }} <span class="text-gray-500">· {{ $report['sold_count'][$i] }} {{ __('шт.') }}</span></td>
                            <td class="py-2 pr-4 text-right">{{ $money($report['paid'][$i] - $report['refunded'][$i]) }}</td>
                            <td class="py-2 pr-4 text-right">{{ $money($report['used'][$i]) }} <span class="text-gray-500">· {{ $report['used_count'][$i] }} {{ __('шт.') }}</span></td>
                            <td class="py-2 pr-4 text-right">{{ $report['free_count'][$i] }}</td>
                            <td class="py-2 text-right">{{ $money($report['free_cost'][$i]) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
