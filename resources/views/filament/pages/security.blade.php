@php
    $labels = ['ok' => 'OK', 'warn' => 'Внимание', 'fail' => 'Ошибка'];
    $order = ['fail' => 0, 'warn' => 1, 'ok' => 2];
    $sorted = collect($results)->sortBy(fn ($row) => $order[$row['status']])->values();
@endphp
<x-filament-panels::page>
    <div class="gf-security">
        <x-filament::section icon="heroicon-o-clipboard-document-check" heading="Проверка настроек"
            :description="$checkedAt ? 'Последняя проверка: ' . $checkedAt . '. Полная проверка (с запросом к сайту) — каждый день в 06:50 и по кнопке.' : 'Проверка ещё не запускалась — нажмите «Проверить сейчас».'">
            @if ($sorted->isNotEmpty())
                <table class="gf-checks">
                    <tbody>
                        @foreach ($sorted as $row)
                            <tr data-status="{{ $row['status'] }}">
                                <td class="gf-checks-status"><span class="gf-checks-badge">{{ $labels[$row['status']] }}</span></td>
                                <td class="gf-checks-name">{{ $row['check'] }}</td>
                                <td class="gf-checks-hint">{{ $row['hint'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </x-filament::section>

        <div class="gf-security-side">
            <x-filament::section icon="heroicon-o-circle-stack" heading="Резервная копия">
                @if ($backup)
                    <p class="gf-security-value">{{ $backup['date'] }}</p>
                    <p class="gf-security-muted">{{ $backup['size'] }} · архив зашифрован</p>
                @else
                    <p class="gf-security-value">Копий нет</p>
                    <p class="gf-security-muted">Создаются каждую ночь в 01:30, если настроен cron (schedule:run).</p>
                @endif
            </x-filament::section>

            <x-filament::section icon="heroicon-o-device-phone-mobile" heading="Двухфакторный вход">
                @if ($withoutTwoFactor === [])
                    <p class="gf-security-value">Настроен у всех</p>
                @else
                    <p class="gf-security-value">Не настроен: {{ count($withoutTwoFactor) }}</p>
                    <p class="gf-security-muted">{{ implode(', ', $withoutTwoFactor) }} — настроят при следующем входе.</p>
                @endif
            </x-filament::section>
        </div>
    </div>
</x-filament-panels::page>
