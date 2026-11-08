<?php

namespace App\Filament\Pages;

use App\Filament\Profile\ApiTokenForm;
use App\Models\ApiToken;
use App\Models\Post;
use App\Support\BrowserSessions;
use App\Support\ProfileOverview;
use App\Support\Rbac;
use Filament\Actions\Action;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Auth\Pages\EditProfile;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Flex;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Enums\VerticalAlignment;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Url;

/**
 * Everyone's own page. It extends Filament's `EditProfile`, so the rate
 * limit, the current-password check and the password rules are Filament's.
 * On top of that it has a header, a list of sections on the left and one
 * section at a time on the right, each drawn by a small view in
 * `resources/views/filament/profile/`.
 *
 * The section shown is the `section` property, which lives in the URL, so a
 * reload or a link lands on the same one.
 */
class Profile extends EditProfile
{
    /** Section key to label and hint, in the order the list shows them. */
    private const SECTIONS = [
        'overview' => ['label' => 'Overview', 'hint' => 'Your work'],
        'account' => ['label' => 'Account', 'hint' => 'Name, email, password'],
        'security' => ['label' => 'Security', 'hint' => 'Sign-in and browsers'],
        'tokens' => ['label' => 'API tokens', 'hint' => 'Scripts and the push command'],
    ];

    #[Url(as: 'section')]
    public string $section = 'overview';

    public function getHeading(): string|Htmlable|null
    {
        return null;
    }

    public function getMaxContentWidth(): Width|string|null
    {
        return Width::SevenExtraLarge;
    }

    /**
     * The sections this account may open. Tokens are for the accounts that
     * hold `token.manage`, so everyone else neither sees the entry nor gets
     * the section by typing `?section=tokens`.
     *
     * @return array<string, array{label: string, hint: string}>
     */
    private function sections(): array
    {
        return array_filter(
            self::SECTIONS,
            fn (string $key): bool => $key !== 'tokens' || $this->mayManageTokens(),
            ARRAY_FILTER_USE_KEY,
        );
    }

    private function currentSection(): string
    {
        return array_key_exists($this->section, $this->sections()) ? $this->section : 'overview';
    }

    private function mayManageTokens(): bool
    {
        return $this->getUser()->can(Rbac::TOKEN_MANAGE);
    }

    public function content(Schema $schema): Schema
    {
        $user = $this->getUser();
        $role = Str::ucfirst((string) $user->roles->first()?->name);

        return $schema
            ->components([
                View::make('filament.profile.header')
                    ->viewData(['user' => $user, 'role' => $role]),
                Flex::make([
                    View::make('filament.profile.nav')
                        ->viewData(['items' => $this->sections(), 'current' => $this->currentSection()])
                        ->grow(false),
                    Group::make($this->sectionComponents()),
                ])->from('lg'),
            ]);
    }

    /** @return array<Component> */
    private function sectionComponents(): array
    {
        return match ($this->currentSection()) {
            'account' => [$this->getFormContentComponent()],
            'security' => $this->securityComponents(),
            'tokens' => $this->tokenComponents(),
            default => [
                View::make('filament.profile.overview')
                    ->viewData(fn (): array => ['overview' => new ProfileOverview($this->getUser())]),
            ],
        };
    }

    /**
     * Two-factor sign-in and where the account is signed in. Filament's own
     * set up, recovery codes and turn off actions are used as they are, only
     * laid out beside a status line instead of in its stock block.
     *
     * @return array<Component>
     */
    private function securityComponents(): array
    {
        $components = [];

        $provider = Filament::getMultiFactorAuthenticationProviders()['app'] ?? null;

        if ($provider instanceof AppAuthentication) {
            $components[] = Flex::make([
                View::make('filament.profile.two-factor')
                    ->viewData(fn (): array => [
                        'enabled' => $provider->isEnabled($this->getUser()),
                        'recoveryCodes' => $provider->isRecoverable() && $provider->isEnabled($this->getUser())
                            ? count($this->getUser()->getAppAuthenticationRecoveryCodes() ?? [])
                            : null,
                    ]),
                Actions::make($provider->getActions())
                    ->key('twoFactorActions')
                    ->grow(false),
            ])->verticalAlignment(VerticalAlignment::Start)->extraAttributes(['class' => 'profile-state-row']);
        }

        // Left out unless sessions are kept in the database, because any
        // other driver would give a list that is wrong.
        $components[] = Group::make([
            Flex::make([
                View::make('filament.profile.sessions-heading'),
                Actions::make([$this->signOutOthersAction()])
                    ->key('sessionActions')
                    ->grow(false),
            ])->verticalAlignment(VerticalAlignment::End),
            View::make('filament.profile.sessions')
                ->viewData(fn (): array => ['sessions' => $this->browserSessions()->all()]),
        ])->visible(fn (): bool => BrowserSessions::isAvailable())
            ->extraAttributes(['class' => 'profile-sessions']);

        return $components;
    }

    /**
     * The account's own tokens. Another Admin's tokens are not listed here,
     * since deleting an account already deletes its tokens.
     *
     * @return array<Component>
     */
    private function tokenComponents(): array
    {
        return [
            Flex::make([
                View::make('filament.profile.tokens-heading'),
                Actions::make([$this->createTokenAction()])
                    ->key('tokenActions')
                    ->grow(false),
            ])->verticalAlignment(VerticalAlignment::End),
            View::make('filament.profile.tokens')
                ->viewData(fn (): array => ['tokens' => $this->getUser()->tokens()->latest()->get()]),
        ];
    }

    /**
     * The token belongs to the account that creates it and can do no more
     * than that account. Sanctum stores only a hash, and the plain text comes
     * back once, in the notice below.
     */
    private function createTokenAction(): Action
    {
        return Action::make('createToken')
            ->label('Create token')
            ->icon(Heroicon::OutlinedPlus)
            ->authorize(fn (): bool => $this->mayManageTokens())
            ->modalHeading('Create an API token')
            ->modalSubmitActionLabel('Create token')
            ->schema(ApiTokenForm::fields())
            ->action(function (array $data): void {
                $created = $this->getUser()->createToken(
                    $data['name'],
                    $data['abilities'],
                    now()->addDays((int) $data['expires_in']),
                );

                Notification::make()
                    ->success()
                    ->title('Token created')
                    ->body(new HtmlString(
                        'Copy it now. It is not shown again.<br><code style="user-select: all; word-break: break-all;">'
                        .e($created->plainTextToken).'</code>',
                    ))
                    ->persistent()
                    ->send();
            });
    }

    /**
     * Mounted from the list with the token's id. It looks the token up among
     * this account's own, so an id that belongs to someone else finds nothing.
     */
    public function revokeTokenAction(): Action
    {
        return Action::make('revokeToken')
            ->authorize(fn (): bool => $this->mayManageTokens())
            ->requiresConfirmation()
            ->modalHeading('Revoke this token?')
            ->modalDescription('Whatever uses it stops working at once. This cannot be undone.')
            ->modalSubmitActionLabel('Revoke')
            ->color('danger')
            ->action(function (array $arguments): void {
                $token = $this->getUser()->tokens()->find($arguments['token'] ?? null);

                if (! $token instanceof ApiToken || ! $this->getUser()->can('delete', $token)) {
                    return;
                }

                $token->delete();

                Notification::make()->success()->title('Token revoked')->send();
            });
    }

    private function signOutOthersAction(): Action
    {
        return Action::make('signOutOthers')
            ->label('Sign out other browsers')
            ->icon(Heroicon::OutlinedArrowRightOnRectangle)
            ->color('gray')
            ->visible(fn (): bool => BrowserSessions::isAvailable() && $this->browserSessions()->hasOthers())
            ->modalHeading('Sign out other browsers?')
            ->modalDescription('Every other browser signed in to your account is signed out. This one stays signed in. Enter your password to confirm.')
            ->modalSubmitActionLabel('Sign out other browsers')
            ->schema([
                TextInput::make('password')
                    ->label('Current password')
                    ->password()
                    ->revealable()
                    ->autocomplete('current-password')
                    ->required()
                    ->currentPassword(guard: Filament::getAuthGuard()),
            ])
            // The password box would otherwise let someone at an unlocked
            // browser test guesses without limit, so five tries a minute.
            ->beforeFormValidated(function (): void {
                $key = $this->signOutThrottleKey();

                if (RateLimiter::tooManyAttempts($key, 5)) {
                    throw ValidationException::withMessages([
                        $this->getSchema($this->getMountedActionSchemaName())->getComponent('password')->getStatePath() => 'Too many attempts. Try again in a minute.',
                    ]);
                }

                RateLimiter::hit($key, 60);
            })
            ->action(function (): void {
                RateLimiter::clear($this->signOutThrottleKey());

                $ended = $this->browserSessions()->signOutOthers();

                Notification::make()
                    ->success()
                    ->title($ended === 1 ? 'Signed out 1 other browser' : "Signed out {$ended} other browsers")
                    ->send();
            });
    }

    private function signOutThrottleKey(): string
    {
        return 'profile-sign-out-others:'.$this->getUser()->getKey();
    }

    private function browserSessions(): BrowserSessions
    {
        return new BrowserSessions($this->getUser(), session()->getId());
    }

    /** Labels sit above their fields here, not beside them, because the sections already use the left column. */
    public function defaultForm(Schema $schema): Schema
    {
        return parent::defaultForm($schema)->inlineLabel(false);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                $this->aside('Name', 'Printed to readers as the author of your posts.', [
                    $this->getNameFormComponent(),
                    Text::make(fn (Get $get): string => (string) $this->nameWarning((string) $get('name')))
                        ->visible(fn (Get $get): bool => $this->nameWarning((string) $get('name')) !== null)
                        ->extraAttributes(['class' => 'profile-warn']),
                ]),
                $this->aside('Role', 'An Admin changes this on the Users screen.', [
                    Text::make(fn (): string => Str::ucfirst((string) $this->getUser()->roles->first()?->name))
                        ->weight('semibold'),
                ]),
                $this->aside(
                    'Email address',
                    Filament::hasEmailChangeVerification()
                        ? 'Used to sign in. A link is sent to the new address, and the change happens when you open it.'
                        : 'Used to sign in.',
                    [$this->getEmailFormComponent()],
                ),
                $this->aside('Password', 'Leave empty to keep the current one. At least 8 characters.', [
                    $this->getPasswordFormComponent(),
                    $this->getPasswordConfirmationFormComponent(),
                ]),
                $this->aside('Confirm it is you', 'Needed to change the email address or the password.', [
                    $this->getCurrentPasswordFormComponent(),
                ])->visible(fn (Get $get): bool => filled($get('password')) || ($get('email') !== $this->getUser()->getAttributeValue('email'))),
            ]);
    }

    /** One setting: its name and a line about it on the left, the fields on the right. */
    private function aside(string $heading, string $description, array $schema): Section
    {
        return Section::make($heading)
            ->description($description)
            ->aside()
            ->extraAttributes(['class' => 'profile-setting'])
            ->schema($schema);
    }

    protected function getNameFormComponent(): Component
    {
        return parent::getNameFormComponent()
            ->label('Full name')
            ->live(debounce: 500);
    }

    /**
     * The name is printed to readers as the author of the person's posts.
     * Once it is being changed, say how many posts that touches. Null while
     * nothing changes or there is nothing to rename.
     */
    private function nameWarning(string $typed): ?string
    {
        $user = $this->getUser();
        $typed = trim($typed);

        if ($typed === '' || $typed === $user->name) {
            return null;
        }

        $posts = Post::query()->where('user_id', $user->getKey());
        $total = (clone $posts)->count();

        if ($total === 0) {
            return null;
        }

        $live = (clone $posts)->published()->count();
        $renames = sprintf('This renames you on %d %s, %d of them live.', $total, $total === 1 ? 'post' : 'posts', $live);

        return $live === 0
            ? "{$renames} Nothing on the site changes."
            : "{$renames} Saving starts a site rebuild, and the live pages show the new name in a few minutes.";
    }
}
