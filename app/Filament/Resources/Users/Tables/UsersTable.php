<?php

namespace App\Filament\Resources\Users\Tables;

use App\Models\User;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Str;

class UsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                // On a phone the address sits under the name, so the role
                // stays in view. From md up the address has a column of its
                // own and the line under the name is hidden (`users-name` in
                // theme.css).
                TextColumn::make('name')
                    ->description(fn (User $record): string => $record->email)
                    ->extraAttributes(['class' => 'users-name'])
                    ->searchable()
                    ->sortable(),
                TextColumn::make('email')
                    ->label('Email address')
                    ->searchable()
                    ->visibleFrom('md'),
                TextColumn::make('role')
                    ->badge()
                    ->state(fn (User $record): string => Str::ucfirst((string) $record->roles->first()?->name))
                    ->placeholder('No role'),
            ])
            ->defaultSort('name')
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    // The policy refuses your own account and the last Admin,
                    // one record at a time.
                    DeleteBulkAction::make()
                        ->authorizeIndividualRecords('delete'),
                ]),
            ]);
    }
}
