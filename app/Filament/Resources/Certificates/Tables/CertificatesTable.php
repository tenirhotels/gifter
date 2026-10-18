<?php

namespace App\Filament\Resources\Certificates\Tables;

use App\Enums\CertificateKind;
use App\Enums\CertificateStatus;
use App\Support\Money;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class CertificatesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('number')->label('Номер')->searchable()->sortable()->copyable(),
                TextColumn::make('status')->label('Статус')->badge()->sortable(),
                TextColumn::make('kind')->label('Тип')->badge()->toggleable(),
                TextColumn::make('customer.full_name')->label('Покупатель')->placeholder('—')->searchable(),
                TextColumn::make('recipient.full_name')->label('Получатель')->searchable(),
                TextColumn::make('total_amount')->label('Сумма')->formatStateUsing(fn ($state) => Money::format($state))->sortable(),
                TextColumn::make('sold_at')->label('Продан')->date('d.m.Y')->sortable(),
                TextColumn::make('valid_until')->label('Действует по')->date('d.m.Y')->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->label('Статус')->options(CertificateStatus::class)->multiple(),
                SelectFilter::make('kind')->label('Тип')->options(CertificateKind::class),
                Filter::make('awaiting_approval')->label('Бесплатные: ждут согласования директора')
                    ->query(fn (Builder $query) => $query->where('status', CertificateStatus::Draft)
                        ->where('kind', CertificateKind::Complimentary)->whereNull('complimentary_approved_at')),
            ])
            ->recordActions([ViewAction::make()])
            ->defaultSort('id', 'desc');
    }
}
