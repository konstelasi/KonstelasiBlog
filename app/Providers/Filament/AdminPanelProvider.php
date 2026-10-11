<?php

namespace App\Providers\Filament;

use App\Filament\Pages\Dashboard;
use App\Filament\Pages\Profile;
use App\Jobs\RebuildSite;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Auth\Pages\PasswordReset\RequestPasswordReset;
use Filament\Auth\Pages\PasswordReset\ResetPassword;
use Filament\FontProviders\LocalFontProvider;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            // No ->registration(). Accounts are made with
            // `php artisan make:filament-user` and then given a role.
            ->login()
            // "Forgot password?" mails a reset link, so it exists only when a
            // real mailer is set. Without one the link would go to the log
            // while the page claimed it was sent. An Admin can always set a
            // new password on the Users screen.
            ->passwordReset(
                self::mailIsSet() ? RequestPasswordReset::class : null,
                self::mailIsSet() ? ResetPassword::class : null,
            )
            // Everyone's own page. Filament draws the profile page without the
            // sidebar and top bar unless it is told it is not simple.
            ->profile(Profile::class, isSimple: false)
            // Changing an email address mails a link to the new address, and
            // a notice with a block link to the old one. It is on whenever a
            // real mailer is set, so a checkout with `MAIL_MAILER=log` still
            // changes the address at once, with the current password.
            ->emailChangeVerification(fn (): bool => self::mailIsSet())
            // Optional for everyone. A person turns it on from their profile,
            // and an Admin can switch it off for a lost phone (the Users edit
            // page, or `php artisan mfa:reset`). Recovery codes are shown once.
            ->multiFactorAuthentication([
                AppAuthentication::make()
                    ->recoverable()
                    ->brandName('Konstelasi Blog'),
            ])
            // A resource, page or action with no rule to ask throws, instead
            // of quietly allowing everyone, which is what Filament does by default.
            ->strictAuthorization()
            ->brandName('Konstelasi Blog')
            // The site's logo drawn inline, so it takes the text colour in
            // both themes. An <img> could not follow the theme.
            ->brandLogo(fn () => view('filament.brand-logo'))
            ->brandLogoHeight('2rem')
            ->favicon('/favicon.svg')
            ->colors([
                'primary' => self::violet(),
                'gray' => self::greys(),
            ])
            // Poppins is bundled into the theme by Vite, so the host never
            // calls a font CDN.
            ->font('Poppins', provider: LocalFontProvider::class)
            ->viteTheme('resources/css/filament/admin/theme.css')
            // A rebuild runs after the response, so a failure can't be shown
            // when it happens. `RebuildSite` leaves a note, and this shows it.
            ->renderHook(PanelsRenderHook::CONTENT_START, function (): string {
                $failedAt = RebuildSite::failedAt();

                return $failedAt ? view('filament.rebuild-warning', ['failedAt' => $failedAt])->render() : '';
            })
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            // The blog's numbers and what needs a decision. Each widget in
            // `Filament/Widgets` decides who sees it. The account box is gone,
            // since the user menu already leads to the profile page.
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }

    /**
     * True when the app sends real mail, which is what the password reset and
     * the email change check both need.
     */
    private static function mailIsSet(): bool
    {
        return ! in_array(config('mail.default'), ['log', 'array'], true);
    }

    /**
     * The site's one hue. Filament draws light-mode text, buttons and focus
     * rings from shade 600 and dark-mode text from shade 400, so those two
     * hold the site's `--brand` values (#311b92 and #b39ddb). The theme
     * points dark-mode shade 500 at 400 as well, so focus rings match.
     *
     * @return array<int, string>
     */
    private static function violet(): array
    {
        return [
            50 => '#f3f0fa',
            100 => '#e6e0f4',
            200 => '#d1c4e9',
            300 => '#c2b0e2',
            400 => '#b39ddb',
            500 => '#7e57c2',
            600 => '#311b92',
            700 => '#28167a',
            800 => '#1f1063',
            900 => '#170b4c',
            950 => '#0e0733',
        ];
    }

    /**
     * Neutral greys from the site's tokens. Filament paints the light page
     * with 50, dark panels with 900 and the dark page with 950, and uses
     * 500 and 600 for secondary text, which is where `--text-faint` and
     * `--text-dim` sit.
     *
     * @return array<int, string>
     */
    private static function greys(): array
    {
        return [
            50 => '#f6f6f6',  // --bg (light)
            100 => '#eeeeee', // --bg-raised (light)
            200 => '#e0e0e0', // --panel-2 (light)
            300 => '#d2d2d2', // --border (light)
            400 => '#aaaaaa', // --text-dim (dark)
            500 => '#626262', // --text-faint (light)
            600 => '#555555', // --text-dim (light)
            700 => '#3f3f3f', // --border-bright (dark)
            800 => '#2d2d2d', // --border (dark)
            900 => '#181818', // --panel (dark)
            950 => '#090909', // --bg (dark)
        ];
    }
}
