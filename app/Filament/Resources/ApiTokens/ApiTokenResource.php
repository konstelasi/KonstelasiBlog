<?php

namespace App\Filament\Resources\ApiTokens;

use App\Filament\Resources\ApiTokens\Pages\CreateApiToken;
use App\Filament\Resources\ApiTokens\Pages\ListApiTokens;
use App\Filament\Resources\ApiTokens\Schemas\ApiTokenForm;
use App\Filament\Resources\ApiTokens\Tables\ApiTokensTable;
use App\Models\ApiToken;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * The API tokens. `ApiTokenPolicy` hides the screen from everyone without
 * `token.manage`, menu entry included. A token is created here, shown once
 * and revoked here. There is no edit page, because a token that changes what
 * it may do under the same secret is harder to reason about than a new one.
 */
class ApiTokenResource extends Resource
{
    protected static ?string $model = ApiToken::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedKey;

    protected static ?string $modelLabel = 'API token';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return ApiTokenForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ApiTokensTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListApiTokens::route('/'),
            'create' => CreateApiToken::route('/create'),
        ];
    }
}
