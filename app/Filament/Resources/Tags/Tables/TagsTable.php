<?php

namespace App\Filament\Resources\Tags\Tables;

use App\Models\Tag;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class TagsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->withCount([
                'posts',
                'posts as live_posts_count' => fn (Builder $posts) => $posts->published(),
            ]))
            ->columns([
                TextColumn::make('name_en')
                    ->label('English')
                    ->description(fn (Tag $record): string => $record->slug)
                    ->searchable(['name_en', 'slug'])
                    ->sortable(),
                TextColumn::make('name_id')
                    ->label('Indonesian')
                    ->searchable()
                    ->sortable()
                    ->visibleFrom('md'),
                // The site builds a tag page only from two live posts up, so
                // the count tells an editor whether the tag has a page yet.
                TextColumn::make('live_posts_count')
                    ->label('Live posts')
                    ->numeric()
                    ->sortable()
                    ->alignEnd(),
                TextColumn::make('posts_count')
                    ->label('All posts')
                    ->numeric()
                    ->sortable()
                    ->alignEnd()
                    ->visibleFrom('md'),
            ])
            ->defaultSort('name_en')
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->modalDescription('The tags leave every post that carries them. Live posts are rebuilt without them.'),
                ]),
            ]);
    }
}
