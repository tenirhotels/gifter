<?php

namespace App\Filament\Resources\Refunds;

use App\Enums\Permission;
use App\Enums\RefundStatus;
use App\Filament\Support\RefundActions;
use App\Filament\Support\RefundColumns;
use App\Filament\Support\TranslatesResourceLabels;
use App\Models\Refund;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class RefundResource extends Resource
{
    use TranslatesResourceLabels;

    protected static ?string $model = Refund::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedReceiptRefund;

    protected static string|UnitEnum|null $navigationGroup = 'Деньги';

    protected static ?int $navigationSort = 2;

    protected static ?string $modelLabel = 'возврат';

    protected static ?string $pluralModelLabel = 'Возвраты';

    public static function canViewAny(): bool
    {
        $user = auth()->user();

        return $user?->can(Permission::ApproveRefunds) || $user?->can(Permission::RequestRefunds);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function getNavigationBadge(): ?string
    {
        if (! auth()->user()?->can(Permission::ApproveRefunds)) {
            return null;
        }
        $count = Refund::query()->whereIn('status', [RefundStatus::Requested, RefundStatus::Approved])->count();

        return $count ? (string) $count : null;
    }

    public static function table(Table $table): Table
    {
        return RefundColumns::apply($table, withCertificate: true)
            ->modifyQueryUsing(fn ($query) => $query->with(['certificate', 'requester', 'reviewer']))
            ->filters([SelectFilter::make('status')->label('Статус')->options(RefundStatus::class)])
            ->recordActions(RefundActions::make())
            ->defaultSort('id', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListRefunds::route('/')];
    }
}
