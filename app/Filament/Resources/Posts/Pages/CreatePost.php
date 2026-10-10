<?php

namespace App\Filament\Resources\Posts\Pages;

use App\Enums\PostStatus;
use App\Filament\Resources\Posts\Pages\Concerns\ReportsRebuild;
use App\Filament\Resources\Posts\PostResource;
use App\Models\Post;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;

class CreatePost extends CreateRecord
{
    use ReportsRebuild;

    protected static string $resource = PostResource::class;

    /**
     * The writer is whoever is signed in, never what the browser sent. The
     * name is stored too, as the byline to fall back on if the account is
     * deleted later. Someone who may not publish always starts a draft with
     * no date, whatever the browser sent.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $user = auth()->user();

        $data['user_id'] = $user->getKey();
        $data['author'] = $user->name;

        if ($user->cannot('publish', Post::class)) {
            $data['status'] = PostStatus::Draft;
            unset($data['published_at']);
        }

        return $data;
    }

    protected function getCreatedNotification(): ?Notification
    {
        return $this->withRebuildNotice(parent::getCreatedNotification(), $this->getRecord());
    }
}
