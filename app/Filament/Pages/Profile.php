<?php

namespace App\Filament\Pages;

use App\Filament\Resources\Posts\PostResource;
use App\Filament\Resources\Posts\Tables\PostsTable;
use App\Models\Post;
use App\Support\ProfileOverview;
use Filament\Actions\Action;
use Filament\Auth\Pages\EditProfile;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Str;

/**
 * Everyone's own page. It extends Filament's `EditProfile`, so the rate
 * limit, the current-password check and the password rules are Filament's,
 * and adds what a shared blog needs on top: an overview of the person's own
 * work, the role, and a warning that renaming yourself renames the author on
 * your posts.
 */
class Profile extends EditProfile implements HasTable
{
    use InteractsWithTable;

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                Tabs::make('Profile')
                    ->persistTabInQueryString()
                    ->tabs([
                        Tab::make('Overview')
                            ->schema([
                                View::make('filament.profile.overview')
                                    ->viewData(fn (): array => ['overview' => new ProfileOverview($this->getUser())]),
                                EmbeddedTable::make(),
                            ]),
                        Tab::make('Account')
                            ->schema([
                                $this->getFormContentComponent(),
                            ]),
                        Tab::make('Security')
                            ->schema([
                                // Filament's own set up, recovery codes and turn
                                // off actions for the authenticator app.
                                $this->getMultiFactorAuthenticationContentComponent(),
                            ]),
                    ]),
            ]);
    }

    /** The person's own posts, with the same ticks as the Posts list. */
    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => Post::query()->where('user_id', $this->getUser()->getKey()))
            ->heading('Your posts')
            ->columns(PostsTable::summaryColumns())
            ->defaultSort('updated_at', 'desc')
            ->paginated([5, 10, 25])
            ->defaultPaginationPageOption(5)
            ->recordUrl(fn (Post $record): string => PostResource::getUrl(
                PostResource::can('update', $record) ? 'edit' : 'view',
                ['record' => $record],
            ))
            ->recordActions([
                Action::make('edit')
                    ->icon(Heroicon::OutlinedPencilSquare)
                    ->url(fn (Post $record): string => PostResource::getUrl('edit', ['record' => $record]))
                    ->visible(fn (Post $record): bool => PostResource::can('update', $record)),
                Action::make('read')
                    ->label('View')
                    ->icon(Heroicon::OutlinedEye)
                    ->color('gray')
                    ->url(fn (Post $record): string => PostResource::getUrl('view', ['record' => $record]))
                    ->visible(fn (Post $record): bool => ! PostResource::can('update', $record)),
            ])
            ->emptyStateHeading('You have not written a post yet')
            ->emptyStateDescription('Posts you start show up here.');
    }

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
