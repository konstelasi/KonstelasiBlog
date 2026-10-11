<?php

namespace App\Filament\Resources\Tags\Pages;

use App\Filament\Resources\Tags\TagResource;
use App\Models\Tag;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditTag extends EditRecord
{
    protected static string $resource = TagResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->modalDescription(fn (Tag $record): string => $record->isOnPublicSite()
                    ? 'A live post carries this tag. It leaves every post and the tag page goes offline.'
                    : 'No live post carries this tag. It leaves the draft posts that have it.'),
        ];
    }
}
