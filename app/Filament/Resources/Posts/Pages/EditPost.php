<?php

namespace App\Filament\Resources\Posts\Pages;

use App\Enums\PostStatus;
use App\Filament\Resources\Posts\Pages\Concerns\ReportsRebuild;
use App\Filament\Resources\Posts\PostResource;
use App\Models\Post;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditPost extends EditRecord
{
    use ReportsRebuild;

    protected static string $resource = PostResource::class;

    /**
     * Set for the length of one save, once the writer has agreed to take the
     * post offline. It is protected on purpose, because a public Livewire
     * property can be set from the browser and would skip the question.
     */
    protected bool $unpublishConfirmed = false;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->modalDescription(fn (Post $record): string => $record->status === PostStatus::Published
                    ? $record->offlineWarning()
                    : 'This draft is not on the site. Delete it anyway?'),
        ];
    }

    /**
     * Someone who may not publish never changes the status or the date, even
     * if the browser sent them. Their own drafts stay drafts.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        if (auth()->user()->cannot('publish', Post::class)) {
            unset($data['status'], $data['published_at']);
        }

        return $data;
    }

    /**
     * The Save button submits the form, which a confirmation modal can't
     * interrupt. So a save that would unpublish a live post stops here, asks
     * through `confirmUnpublishAction()`, and saves again once the writer agrees.
     */
    protected function beforeSave(): void
    {
        if ($this->unpublishConfirmed || ! $this->isUnpublishing()) {
            return;
        }

        $this->mountAction('confirmUnpublish');
        $this->halt();
    }

    public function confirmUnpublishAction(): Action
    {
        return Action::make('confirmUnpublish')
            ->requiresConfirmation()
            ->color('danger')
            ->modalHeading('Take this post offline?')
            ->modalDescription(fn (): string => $this->getRecord()->offlineWarning())
            ->modalSubmitActionLabel('Save and unpublish')
            ->action(function (): void {
                $this->unpublishConfirmed = true;

                try {
                    $this->save();
                } finally {
                    $this->unpublishConfirmed = false;
                }
            });
    }

    protected function getSavedNotification(): ?Notification
    {
        return $this->withRebuildNotice(parent::getSavedNotification(), $this->getRecord());
    }

    /** Whether the stored post is live and the form now says draft. */
    private function isUnpublishing(): bool
    {
        $status = $this->data['status'] ?? null;
        $status = $status instanceof PostStatus ? $status : PostStatus::tryFrom((string) $status);

        return $this->getRecord()->status === PostStatus::Published && $status === PostStatus::Draft;
    }
}
