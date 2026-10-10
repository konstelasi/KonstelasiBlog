<?php

namespace App\Filament\Pages;

use App\Models\Post;
use Filament\Auth\Pages\EditProfile;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

/**
 * Everyone's own page. It extends Filament's `EditProfile`, so the rate
 * limit, the current-password check and the password rules are Filament's,
 * and adds what a shared blog needs on top: the role, and a warning that
 * renaming yourself renames the author on your posts.
 */
class Profile extends EditProfile
{
    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                $this->getNameFormComponent(),
                $this->getEmailFormComponent(),
                $this->getPasswordFormComponent(),
                $this->getPasswordConfirmationFormComponent(),
                $this->getCurrentPasswordFormComponent(),
                $this->getRoleFormComponent(),
            ]);
    }

    protected function getNameFormComponent(): Component
    {
        return parent::getNameFormComponent()
            ->live(debounce: 500)
            ->helperText(fn (Get $get): string => $this->nameHelper((string) $get('name')));
    }

    /** Shown, never saved. An Admin changes roles on the Users screen. */
    protected function getRoleFormComponent(): Component
    {
        return TextInput::make('role')
            ->disabled()
            ->dehydrated(false)
            ->formatStateUsing(fn (): string => Str::ucfirst((string) $this->getUser()->roles->first()?->name))
            ->helperText('An Admin changes your role.');
    }

    /**
     * The name is printed to readers as the author of the person's posts.
     * Once it is being changed, say how many posts that touches.
     */
    private function nameHelper(string $typed): string
    {
        $base = 'Printed to readers as the author of your posts.';
        $user = $this->getUser();
        $typed = trim($typed);

        if ($typed === '' || $typed === $user->name) {
            return $base;
        }

        $posts = Post::query()->where('user_id', $user->getKey());
        $total = (clone $posts)->count();

        if ($total === 0) {
            return $base;
        }

        $live = (clone $posts)->published()->count();

        return sprintf(
            'This changes the author name on %d %s, %d of them live. The live pages change when the site is rebuilt, which starts when you save.',
            $total,
            $total === 1 ? 'post' : 'posts',
            $live,
        );
    }
}
