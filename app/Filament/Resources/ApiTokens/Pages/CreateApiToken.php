<?php

namespace App\Filament\Resources\ApiTokens\Pages;

use App\Filament\Resources\ApiTokens\ApiTokenResource;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;

class CreateApiToken extends CreateRecord
{
    protected static string $resource = ApiTokenResource::class;

    /** The secret, kept only until the notice that shows it has been built. */
    private ?string $plainTextToken = null;

    /**
     * The token belongs to the account that creates it and can do no more
     * than that account. Sanctum stores only a hash, and the plain text comes
     * back once, here.
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $created = auth()->user()->createToken(
            $data['name'],
            $data['abilities'],
            now()->addDays((int) $data['expires_in']),
        );

        $this->plainTextToken = $created->plainTextToken;

        return $created->accessToken;
    }

    protected function getCreatedNotification(): ?Notification
    {
        return Notification::make()
            ->success()
            ->title('Token created')
            ->body(new HtmlString(
                'Copy it now. It is not shown again.<br><code style="user-select: all; word-break: break-all;">'
                .e((string) $this->plainTextToken).'</code>',
            ))
            ->persistent();
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }
}
