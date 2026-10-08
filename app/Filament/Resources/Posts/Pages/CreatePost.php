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

    protected function getCreatedNotification(): ?Notification
    {
        return $this->withRebuildNotice(parent::getCreatedNotification(), $this->getRecord());
    }
}
