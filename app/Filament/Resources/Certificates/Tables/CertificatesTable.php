<?php

namespace App\Filament\Resources\Certificates\Tables;

use App\Enums\CertificateKind;
use App\Enums\CertificateStatus;
use App\Filament\Resources\CertificateBatches\CertificateBatchResource;
use App\Models\AuditLog;
use App\Models\Certificate;
use App\Support\Money;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\TextInput;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class CertificatesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('batch.package'))
            // Переключатель «Группировать → Группа»: сертификаты групп собираются под заголовком группы.
            ->groups([
                Group::make('batch_id')->label('Группа')->titlePrefixedWithLabel(false)->collapsible()->orderQueryUsing(fn (Builder $query, string $direction) => $query->orderBy('batch_id', 'desc'))
                    ->getTitleFromRecordUsing(fn (Certificate $record) => $record->batch?->label() ?? __('Без группы')),
            ])
            ->columns([
                TextColumn::make('number')->label('Номер')->searchable()->sortable()->copyable(),
                TextColumn::make('status')->label('Статус')->badge()->sortable(),
                TextColumn::make('kind')->label('Вид выпуска')->badge()->toggleable(),
                TextColumn::make('customer.full_name')->label('Заказчик')->placeholder('—')->searchable(),
                TextColumn::make('recipient.full_name')->label('Получатель')->searchable(),
                TextColumn::make('total_amount')->label('Сумма')->formatStateUsing(fn ($state) => Money::format($state))->sortable(),
                TextColumn::make('batch_id')->label('Группа')->placeholder('—')->toggleable()
                    ->formatStateUsing(fn ($state) => '№'.$state)
                    ->url(fn (Certificate $record) => $record->batch_id ? CertificateBatchResource::getUrl('view', ['record' => $record->batch_id]) : null),
                TextColumn::make('sold_at')->label('Дата продажи')->date('d.m.Y')->sortable(),
                TextColumn::make('valid_until')->label('Действует по')->date('d.m.Y')->sortable()
                    ->color(fn ($record) => in_array($record->status, [CertificateStatus::Active, CertificateStatus::Reserved], true)
                        && $record->valid_until->lte(today()->addDays(14)) ? 'danger' : null),
            ])
            ->filters([
                SelectFilter::make('status')->label('Статус')->options(CertificateStatus::class)->multiple(),
                SelectFilter::make('kind')->label('Вид выпуска')->options(CertificateKind::class),
                Filter::make('mine')->label('Только мои')
                    ->query(fn (Builder $query) => $query->where('created_by', auth()->id())),
                Filter::make('no_recipient')->label('Без получателя')
                    ->query(fn (Builder $query) => $query->whereNull('recipient_id')),
                Filter::make('batch')->schema([TextInput::make('batch_id')->label('Из группы №')->integer()->minValue(1)])
                    ->query(fn (Builder $query, array $data) => $query->when($data['batch_id'] ?? null, fn (Builder $q, $id) => $q->where('batch_id', (int) $id)))
                    ->indicateUsing(fn (array $data) => ($data['batch_id'] ?? null) ? __('Группа №:p1', ['p1' => $data['batch_id']]) : null),
                Filter::make('expiring')->label('Истекают в ближайшие 14 дней')
                    ->query(fn (Builder $query) => $query->whereIn('status', [CertificateStatus::Active, CertificateStatus::Reserved])
                        ->whereDate('valid_until', '<=', today()->addDays(14)->toDateString())),
                Filter::make('awaiting_approval')->label('Бесплатные: ждут согласования директора')
                    ->query(fn (Builder $query) => $query->where('status', CertificateStatus::Draft)
                        ->where('kind', CertificateKind::Complimentary)->whereNull('complimentary_approved_at')),
                Filter::make('manual_override')->label('Правки даты или продавца (30 дней)')
                    ->query(fn (Builder $query) => $query->whereIn('id', AuditLog::query()->whereIn('event', ['sale_date_overridden', 'seller_overridden'])
                        ->where('auditable_type', (new Certificate)->getMorphClass())->where('created_at', '>=', now()->subDays(30))->select('auditable_id'))),
            ])
            ->recordActions([ViewAction::make()])
            ->defaultSort('id', 'desc');
    }
}
