<?php

namespace App\Filament\Resources\Recipients;

use App\Filament\Resources\Recipients\Pages\CreateRecipient;
use App\Filament\Resources\Recipients\Pages\EditRecipient;
use App\Filament\Resources\Recipients\Pages\ListRecipients;
use App\Filament\Resources\Recipients\Schemas\RecipientForm;
use App\Filament\Resources\Recipients\Tables\RecipientsTable;
use App\Models\Recipient;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class RecipientResource extends Resource
{
    protected static ?string $model = Recipient::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedGift;

    protected static string|UnitEnum|null $navigationGroup = 'Продажи';

    protected static ?int $navigationSort = 3;

    protected static ?string $modelLabel = 'получатель';

    protected static ?string $pluralModelLabel = 'Получатели';

    protected static ?string $recordTitleAttribute = 'full_name';

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return RecipientForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return RecipientsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRecipients::route('/'),
            'create' => CreateRecipient::route('/create'),
            'edit' => EditRecipient::route('/{record}/edit'),
        ];
    }
}
