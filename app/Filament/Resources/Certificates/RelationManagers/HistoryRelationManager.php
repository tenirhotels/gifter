<?php

namespace App\Filament\Resources\Certificates\RelationManagers;

use App\Enums\CertificateStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\RefundStatus;
use App\Models\CertificateHistory;
use App\Models\Customer;
use App\Models\Package;
use App\Models\Recipient;
use App\Support\Money;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Carbon;

class HistoryRelationManager extends RelationManager
{
    protected static string $relationship = 'history';

    protected static ?string $title = 'История изменений';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')->label('Когда')->dateTime('d.m.Y H:i'),
                TextColumn::make('user.name')->label('Кто')->placeholder(__('система')),
                TextColumn::make('event')->label('Событие')->badge(),
                TextColumn::make('description')->label('Подробности')->placeholder('—')->wrap(),
                TextColumn::make('changes')->label('Было → стало')->wrap()->state(fn (CertificateHistory $record) => self::changes($record)),
            ])
            ->defaultSort('id', 'desc');
    }

    /** @return array<string, string> */
    private static function labels(): array
    {
        return [
            'status' => __('статус'), 'customer_id' => __('заказчик'), 'recipient_id' => __('получатель'), 'sold_at' => __('продан'),
            'valid_from' => __('действует с'), 'valid_until' => __('действует по'), 'notes' => __('примечание'), 'package_id' => __('пакет'),
            'method' => __('способ'), 'amount' => __('сумма'), 'invoice_number' => __('№ счёта'), 'paid_at' => __('оплачен'),
            'service' => __('услуга'), 'quantity' => __('кол-во'), 'unit_price' => __('цена'), 'line_total' => __('сумма'),
            'refund_status' => __('возврат'), 'reconciled' => __('сверено'),
        ];
    }

    private static function changes(CertificateHistory $record): ?string
    {
        $before = $record->before ?? [];
        $after = $record->after ?? [];
        $lines = [];
        foreach (array_unique([...array_keys($before), ...array_keys($after)]) as $key) {
            $old = $before[$key] ?? null;
            $new = $after[$key] ?? null;
            if (! isset(self::labels()[$key]) || is_array($old) || is_array($new) || $old === $new) {
                continue;
            }
            $lines[] = self::labels()[$key].': '.($old === null ? '' : self::value($key, $old).' → ').self::value($key, $new);
        }

        return $lines ? implode('; ', $lines) : null;
    }

    private static function value(string $key, mixed $value): string
    {
        return match (true) {
            $value === null || $value === '' => '—',
            $key === 'refund_status' => RefundStatus::tryFrom($value)?->getLabel() ?? (string) $value,
            $key === 'reconciled' => $value ? __('да') : __('нет'),
            $key === 'status' => CertificateStatus::tryFrom($value)?->getLabel() ?? PaymentStatus::tryFrom($value)?->getLabel() ?? (string) $value,
            $key === 'method' => PaymentMethod::tryFrom($value)?->getLabel() ?? (string) $value,
            in_array($key, ['amount', 'unit_price', 'line_total'], true) => Money::format($value),
            in_array($key, ['sold_at', 'valid_from', 'valid_until'], true) => Carbon::parse($value)->format('d.m.Y'),
            $key === 'customer_id' => Customer::find($value)?->full_name ?? "#{$value}",
            $key === 'recipient_id' => Recipient::find($value)?->full_name ?? "#{$value}",
            $key === 'package_id' => Package::find($value)?->name ?? "#{$value}",
            $key === 'paid_at' => Carbon::parse($value)->format('d.m.Y H:i'),
            default => (string) $value,
        };
    }
}
