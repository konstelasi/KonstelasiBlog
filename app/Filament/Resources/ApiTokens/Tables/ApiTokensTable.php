<?php

namespace App\Filament\Resources\ApiTokens\Tables;

use App\Models\ApiToken;
use Filament\Actions\DeleteAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ApiTokensTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('tokenable'))
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('tokenable.name')
                    ->label('Account')
                    ->visibleFrom('md'),
                TextColumn::make('abilities')
                    ->state(fn (ApiToken $record): array => $record->abilityLabels())
                    ->badge(),
                TextColumn::make('last_used_at')
                    ->label('Last used')
                    ->since()
                    ->placeholder('Never')
                    ->sortable()
                    ->visibleFrom('md'),
                TextColumn::make('expires_at')
                    ->label('Expires')
                    ->dateTime('j M Y')
                    ->description(fn (ApiToken $record): ?string => $record->isExpired() ? 'Expired' : null)
                    ->color(fn (ApiToken $record): ?string => $record->isExpired() ? 'danger' : null)
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([
                DeleteAction::make()
                    ->label('Revoke')
                    ->modalHeading('Revoke this token?')
                    ->modalDescription('Whatever uses it stops working at once. This cannot be undone.')
                    ->successNotificationTitle('Token revoked'),
            ]);
    }
}
