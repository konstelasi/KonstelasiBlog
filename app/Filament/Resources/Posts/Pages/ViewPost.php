<?php

namespace App\Filament\Resources\Posts\Pages;

use App\Filament\Resources\Posts\Pages\Concerns\PreviewsPost;
use App\Filament\Resources\Posts\PostResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

/**
 * The read-only page. A Writer can read every post but only edit their own
 * drafts, and Filament draws the form with every field disabled here.
 */
class ViewPost extends ViewRecord
{
    use PreviewsPost;

    protected static string $resource = PostResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->previewAction(),
            EditAction::make(),
        ];
    }
}
