<x-filament-widgets::widget>
    @if ($empty)
        <x-filament::section>
            <x-filament::empty-state
                :contained="false"
                icon="heroicon-o-chart-bar"
                :heading="__('Нет данных за период «:p1»', ['p1' => $period])"
                :description="__('Здесь появятся продажи, оплаты и использование сертификатов. Выберите другой период или внесите сертификаты (в том числе перенос из таблицы).')"
            />
        </x-filament::section>
    @elseif ($unknownDate > 0)
        <x-filament::section icon="heroicon-o-information-circle" icon-color="warning" :heading="__('Данные об использовании неполные')">
            {{ __('У :p1 использованных сертификатов дата использования неизвестна (нет брони с заездом и отметки в истории). Они учтены в итогах «Всё время», но не попадают в графики по месяцам. После проверки броней (gifter:import-check-bookings) часть дат появится.', ['p1' => $unknownDate]) }}
        </x-filament::section>
    @endif
</x-filament-widgets::widget>
