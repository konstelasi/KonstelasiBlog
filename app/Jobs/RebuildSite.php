<?php

namespace App\Jobs;

use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Asks GitHub to run the website's deploy workflow, which rebuilds the public
 * blog pages from this app's API and uploads them.
 *
 * It never fails a save. Without a token (local development, tests) it does
 * nothing, and a GitHub error is only logged.
 */
class RebuildSite
{
    use Dispatchable;

    public function handle(): void
    {
        $token = config('services.github.token');
        if (! $token) {
            return;
        }

        $url = sprintf(
            'https://api.github.com/repos/%s/actions/workflows/%s/dispatches',
            config('services.github.repo'),
            config('services.github.workflow'),
        );

        try {
            $response = Http::withToken($token)
                ->acceptJson()
                ->withHeaders(['X-GitHub-Api-Version' => '2022-11-28'])
                ->timeout(5)
                ->post($url, ['ref' => config('services.github.ref')]);

            if ($response->failed()) {
                Log::warning('The site rebuild was not started.', ['status' => $response->status()]);
            }
        } catch (Throwable $e) {
            Log::warning('The site rebuild was not started.', ['error' => $e::class]);
        }
    }
}
