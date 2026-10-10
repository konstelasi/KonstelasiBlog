<?php

namespace App\Support;

/**
 * Turns a browser's user agent string into the short description a person
 * recognises, such as "Chrome on Windows". It only guesses from the usual
 * markers, which is enough to tell your own browsers apart.
 */
final class UserAgent
{
    public static function describe(?string $agent): string
    {
        $agent = (string) $agent;

        if ($agent === '') {
            return 'Unknown browser';
        }

        return self::browser($agent).' on '.self::system($agent);
    }

    private static function browser(string $agent): string
    {
        // The order matters, because most browsers also say "Chrome" or "Safari".
        return match (true) {
            str_contains($agent, 'Edg/'), str_contains($agent, 'EdgA/'), str_contains($agent, 'EdgiOS/') => 'Edge',
            str_contains($agent, 'OPR/'), str_contains($agent, 'Opera') => 'Opera',
            str_contains($agent, 'Firefox/'), str_contains($agent, 'FxiOS/') => 'Firefox',
            str_contains($agent, 'Chrome/'), str_contains($agent, 'CriOS/') => 'Chrome',
            str_contains($agent, 'Safari/') => 'Safari',
            default => 'Unknown browser',
        };
    }

    private static function system(string $agent): string
    {
        // iPhone and iPad agents also say "like Mac OS X", so they come first.
        return match (true) {
            str_contains($agent, 'iPhone'), str_contains($agent, 'iPad') => 'iOS',
            str_contains($agent, 'Android') => 'Android',
            str_contains($agent, 'Windows') => 'Windows',
            str_contains($agent, 'Mac OS X'), str_contains($agent, 'Macintosh') => 'macOS',
            str_contains($agent, 'CrOS') => 'ChromeOS',
            str_contains($agent, 'Linux') => 'Linux',
            default => 'an unknown system',
        };
    }
}
