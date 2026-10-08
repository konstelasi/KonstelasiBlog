<?php

namespace App\Filament\Resources\Posts\Tables;

use App\Enums\PostStatus;
use App\Models\Post;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class PostsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title_en')
                    ->label('Title')
                    ->placeholder('Untitled')
                    ->searchable(['title_en', 'title_id', 'slug'])
                    ->description(fn (Post $record): string => $record->slug)
                    ->wrap(),
                TextColumn::make('status')
                    ->badge(),
                TextColumn::make('published_at')
                    ->label('Published')
                    ->date()
                    ->placeholder('Not yet')
                    ->sortable(),
            ])
            // Drafts in progress first, then the newest published post.
            ->defaultSort(fn (Builder $query): Builder => $query
                ->orderByRaw('published_at is null desc')
                ->orderByDesc('published_at')
                ->orderByDesc('id'))
            ->filters([
                SelectFilter::make('status')
                    ->options(PostStatus::class),
            ])
            ->recordActions([
                // Only a published post has an address to open.
                Action::make('view')
                    ->label('View on site')
                    ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                    ->url(fn (Post $record): string => $record->publicUrl())
                    ->openUrlInNewTab()
                    ->visible(fn (Post $record): bool => $record->status === PostStatus::Published),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
