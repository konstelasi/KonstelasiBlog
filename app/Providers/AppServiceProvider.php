<?php

namespace App\Providers;

use App\Models\ApiToken;
use Illuminate\Cache\RateLimiting\Limit;
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

        // Our own token model, which the token screen reads helpers from.
        Sanctum::usePersonalAccessTokenModel(ApiToken::class);
    }
}
