<?php

namespace App\Filament\Resources\Posts\Pages\Concerns;

use App\Models\Post;
use Filament\Actions\Action;
use Filament\Notifications\Notification;

/**
 * Adds a line to the "Saved" toast when the save has started a site rebuild,
 * with a link to the workflow's page on GitHub to watch it.
 *
 * The rebuild itself runs after the response, so this only says it has been
 * asked for. Without a token nothing is asked for, and the toast stays plain.
 */
trait ReportsRebuild
{
    protected function withRebuildNotice(?Notification $notification, Post $post): ?Notification
    {
        if (! $notification || ! config('services.github.token') || ! $post->affectsPublicSite()) {
            return $notification;
        }

        $url = sprintf(
            'https://github.com/%s/actions/workflows/%s',
            config('services.github.repo'),
            config('services.github.workflow'),
        );

        return $notification
            ->body('The site is rebuilding. The change is live in a few minutes.')
            ->actions([
                Action::make('watch')
                    ->label('Watch the rebuild')
                    ->url($url, shouldOpenInNewTab: true),
            ]);
    }
}
