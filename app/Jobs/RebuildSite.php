<?php

namespace App\Jobs;

use Carbon\CarbonInterface;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Asks GitHub to run the website's deploy workflow, which rebuilds the public
 * blog pages from this app's API and uploads them.
 *
 * It never fails a save. Without a token (local development, tests) it does
 * nothing, and a GitHub error is logged. It runs after the response, so it
 * can't tell the writer then. It leaves a note in the cache instead, and the
 * admin shows a warning on every page until a later request goes through.
 */
class RebuildSite
{
    use Dispatchable;

    /** Holds the time of the last refused or failed request, until one succeeds. */
    public const FAILED_KEY = 'site-rebuild-failed';

    /**
     * Holds the time GitHub last accepted a request. That is when the rebuild
     * was asked for, not when it finished, which this app cannot know.
     */
    public const REQUESTED_KEY = 'site-rebuild-requested';

    /** When the last request failed, or null if the last one went through. */
    public static function failedAt(): ?CarbonInterface
    {
        return self::timeAt(self::FAILED_KEY);
    }

    /** When GitHub last accepted a request, or null if none has been since the note was kept. */
    public static function requestedAt(): ?CarbonInterface
    {
        return self::timeAt(self::REQUESTED_KEY);
    }

    private static function timeAt(string $key): ?CarbonInterface
    {
        $at = Cache::get($key);

        return $at ? Carbon::createFromTimestamp($at) : null;
    }

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
                self::markFailed();

                return;
            }

            Cache::forget(self::FAILED_KEY);
            Cache::forever(self::REQUESTED_KEY, now()->timestamp);
        } catch (Throwable $e) {
            Log::warning('The site rebuild was not started.', ['error' => $e::class]);
            self::markFailed();
        }
    }

    private static function markFailed(): void
    {
        Cache::forever(self::FAILED_KEY, now()->timestamp);
    }
}
