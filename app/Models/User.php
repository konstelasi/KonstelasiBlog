<?php

namespace App\Models;

use App\Observers\UserObserver;
use App\Support\Rbac;
use Database\Factories\UserFactory;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthentication;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthenticationRecovery;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasAvatar;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use SensitiveParameter;
use Spatie\Permission\Traits\HasRoles;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token', 'app_authentication_secret', 'app_authentication_recovery_codes'])]
#[ObservedBy(UserObserver::class)]
class User extends Authenticatable implements FilamentUser, HasAppAuthentication, HasAppAuthenticationRecovery, HasAvatar
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable;

    /** The posts this account wrote. Imported posts belong to nobody. */
    public function posts(): HasMany
    {
        return $this->hasMany(Post::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            // Encrypted with APP_KEY. Changing that key makes every stored
            // secret unreadable, so turn two-factor off for everyone first.
            'app_authentication_secret' => 'encrypted',
            'app_authentication_recovery_codes' => 'encrypted:array',
        ];
    }

    /** The first letters of the first two words of the name, as the profile page shows them. */
    public function initials(): string
    {
        return Str::of($this->name)->explode(' ')->filter()->take(2)
            ->map(fn (string $word): string => Str::upper(Str::substr($word, 0, 1)))
            ->implode('');
    }

    /**
     * The picture in the top bar and the account widget, drawn here as a grey
     * disc with the initials. Filament's default asks ui-avatars.com, which
     * sends every writer's name to a third party and paints the disc in the
     * panel's darkest grey, so in dark mode it vanishes into the page.
     */
    public function getFilamentAvatarUrl(): ?string
    {
        $initials = htmlspecialchars($this->initials(), ENT_QUOTES);
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64"><circle cx="32" cy="32" r="32" fill="#626262"/>'
            .'<text x="32" y="32" dy=".35em" text-anchor="middle" font-family="Poppins, Arial, sans-serif" font-size="26" font-weight="600" fill="#fff">'.$initials.'</text></svg>';

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }

    /** Whether the account signs in with a code from an authenticator app too. */
    public function hasTwoFactor(): bool
    {
        return filled($this->app_authentication_secret);
    }

    /** Back to password only, for a lost phone. Used by an Admin and by `mfa:reset`. */
    public function resetTwoFactor(): void
    {
        $this->forceFill([
            'app_authentication_secret' => null,
            'app_authentication_recovery_codes' => null,
        ])->save();
    }

    public function getAppAuthenticationSecret(): ?string
    {
        return $this->app_authentication_secret;
    }

    public function saveAppAuthenticationSecret(#[SensitiveParameter] ?string $secret): void
    {
        $this->app_authentication_secret = $secret;
        $this->save();
    }

    /** The name an authenticator app shows beside the six digits. */
    public function getAppAuthenticationHolderName(): string
    {
        return $this->email;
    }

    /** @return ?array<string> */
    public function getAppAuthenticationRecoveryCodes(): ?array
    {
        return $this->app_authentication_recovery_codes;
    }

    /** @param  ?array<string>  $codes */
    public function saveAppAuthenticationRecoveryCodes(#[SensitiveParameter] ?array $codes): void
    {
        $this->app_authentication_recovery_codes = $codes;
        $this->save();
    }

    /**
     * Registration is off, so the only accounts are the ones made with
     * `php artisan make:filament-user`. An account gets into the admin only
     * once it has a role (`php artisan rbac:assign`).
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return $this->hasAnyRole(Rbac::ROLES);
    }
}
