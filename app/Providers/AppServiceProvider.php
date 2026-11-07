<?php

namespace App\Providers;

use App\Models\ApiToken;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Laravel's usual API limit. A site build makes one request, so this
        // only ever stops a scraper.
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(60)->by($request->ip()));

        // The authoring API is counted per token, so one busy token can't
        // use up the allowance of another. A push of a dozen posts is two
        // requests each (a check, then the write).
        RateLimiter::for('posts-write', fn (Request $request) => Limit::perMinute(60)
            ->by($request->user()?->currentAccessToken()?->getKey() ?? $request->ip()));

        // Our own token model, which the token screen reads helpers from.
        Sanctum::usePersonalAccessTokenModel(ApiToken::class);

        // The authoring API stores the text it is sent, as it is sent. Laravel
        // would otherwise trim every string and turn "" into null, which drops
        // a Markdown body's last newline, and then a second push of the same
        // file would show a change.
        TrimStrings::skipWhen(fn (Request $request): bool => $request->is('api/v1/*'));
        ConvertEmptyStringsToNull::skipWhen(fn (Request $request): bool => $request->is('api/v1/*'));
    }
}
