<?php

namespace App\Filament\Resources\Posts\Pages\Concerns;

use App\Models\Post;
use App\Support\PostPreview;
use Filament\Actions\Action;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\View\View;

/**
 * A "Preview" button for the pages that show the post form. It opens the post
 * as readers will see it, from what the form holds right now, so a draft that
 * has not been saved can be read before it is. The content is built each time
 * the panel opens, and the form's raw state is used because a draft may still
 * be missing fields that publishing would require.
 *
 * Anyone who can open the form may preview it, since the preview shows only
 * what the form already shows.
 */
trait PreviewsPost
{
    public function previewAction(): Action
    {
        return Action::make('preview')
            ->label('Preview')
            ->icon(Heroicon::OutlinedEye)
            ->color('gray')
            ->slideOver()
            ->modalWidth(Width::SevenExtraLarge)
            ->modalHeading('Preview')
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Close')
            ->modalContent(fn (): View => view('filament.post-preview', [
                'preview' => PostPreview::fromState(
                    $this->form->getRawState(),
                    $this->record instanceof Post ? $this->record : null,
                ),
            ]));
    }
}
