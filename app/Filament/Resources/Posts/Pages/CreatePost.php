<?php

namespace App\Filament\Resources\Posts\Pages;

use App\Filament\Resources\Posts\Pages\Concerns\ReportsRebuild;
use App\Filament\Resources\Posts\PostResource;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;

class CreatePost extends CreateRecord
{
    use ReportsRebuild;

    protected static string $resource = PostResource::class;

    /**
     * The writer is whoever is signed in, never what the browser sent. The
     * name is stored too, as the byline to fall back on if the account is
     * deleted later.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $user = auth()->user();

        $data['user_id'] = $user->getKey();
        $data['author'] = $user->name;

        return $data;
    }

    protected function getCreatedNotification(): ?Notification
    {
        return $this->withRebuildNotice(parent::getCreatedNotification(), $this->getRecord());
    }
}
