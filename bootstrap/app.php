<?php

use App\Http\Middleware\NoIndex;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Appended globally, so the admin, the API, redirects and error pages
        // all carry the header.
        $middleware->append(NoIndex::class);
        $middleware->throttleApi();
        // Only the host in `APP_URL` is answered (no subdomains). Otherwise a
        // request with a made-up `Host` header would get a reset link mailed
        // out that points at that host. It is skipped when `APP_ENV` is `local`.
        $middleware->trustHosts(
            at: fn (): array => array_filter([
                ($host = parse_url((string) config('app.url'), PHP_URL_HOST)) ? '^'.preg_quote($host).'$' : null,
            ]),
            subdomains: false,
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
