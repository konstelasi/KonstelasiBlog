<?php

namespace App\Filament\Resources\Posts\Tables;

use App\Enums\PostStatus;
use App\Filament\Resources\Posts\PostResource;
use App\Models\Post;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

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
                // A tick means the title, description and body are all written.
                // Publishing needs both, so a draft with a grey cross is not ready.
                IconColumn::make('has_en')
                    ->label('EN')
                    ->state(fn (Post $record): bool => $record->hasLanguage('en'))
                    ->boolean()
                    ->falseColor('gray')
                    ->alignCenter(),
                IconColumn::make('has_id')
                    ->label('ID')
                    ->state(fn (Post $record): bool => $record->hasLanguage('id'))
                    ->boolean()
                    ->falseColor('gray')
                    ->alignCenter(),
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
                // Someone who may not edit a post can still read it.
                Action::make('read')
                    ->label('View')
                    ->icon(Heroicon::OutlinedEye)
                    ->color('gray')
                    ->url(fn (Post $record): string => PostResource::getUrl('view', ['record' => $record]))
                    ->visible(fn (Post $record): bool => ! PostResource::can('update', $record)),
            ])
            // The default row link would open "View on site" for a live post,
            // because that action is named `view`. A row opens the page the
            // person can use, the form to edit or the read-only copy.
            ->recordUrl(fn (Post $record): string => PostResource::getUrl(
                PostResource::can('update', $record) ? 'edit' : 'view',
                ['record' => $record],
            ))
            ->toolbarActions([
                BulkActionGroup::make([
                    // A bulk action only asks the policy's `deleteAny`, so
                    // each post is checked as well. Without this a Writer
                    // could select someone else's post and delete it.
                    DeleteBulkAction::make()
                        ->authorizeIndividualRecords('delete')
                        ->modalDescription(function (Collection $records): string {
                            $live = $records->filter(fn (Post $post): bool => $post->status === PostStatus::Published);

                            return $live->isEmpty()
                                ? 'These drafts are not on the site. Delete them anyway?'
                                : sprintf(
                                    '%d of these posts %s live on the site, and their links will stop working (%s).',
                                    $live->count(),
                                    $live->count() === 1 ? 'is' : 'are',
                                    $live->map(fn (Post $post): string => $post->slug)->implode(', '),
                                );
                        }),
                ]),
            ]);
    }
}
