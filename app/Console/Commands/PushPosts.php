<?php

namespace App\Console\Commands;

use App\Support\PostFiles;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;

/**
 * Sends a folder of Markdown posts, one pair of files per post, to the
 * authoring API of the blog. It reads the same folders `posts:import` reads,
 * but it needs no access to the host: only a token made under API tokens on your profile
 * page, in `BLOG_API_TOKEN`, and the address in `BLOG_API_URL`.
 *
 * Every post is checked first as a dry run, and nothing is written unless
 * all of them pass, so a bad file late in the folder does not leave half of
 * it pushed. Without `--publish` the status is left alone, so a new post is a
 * draft and an existing post keeps whatever status it has.
 *
 * A post can only use tags that exist. A tag a post names that is missing is
 * created first, but only when a `tags.yml` in the folder defines it, and the
 * token has the `tags:manage` ability. A slug with no definition stops the
 * run, so a typo never makes a tag. A tag created before a later post is
 * refused stays, which does no harm, since a tag nobody uses shows nowhere.
 */
#[Signature('posts:push
    {path : Folder that holds the en/ and id/ folders}
    {--publish : Publish the posts, which needs a token with the Publish ability}
    {--dry-run : Check every post with the API and write nothing}')]
#[Description('Send paired English and Indonesian Markdown posts to the blog through its API')]
class PushPosts extends Command
{
    public function handle(): int
    {
        $token = (string) config('services.blog_api.token');
        if ($token === '') {
            $this->error('BLOG_API_TOKEN is not set. Make a token under API tokens on your profile page and put it in .env.');

            return self::FAILURE;
        }

        $root = PostFiles::resolve($this->argument('path'));
        if ($root === null) {
            $this->error("No en/ and id/ folders found under {$this->argument('path')}.");

            return self::FAILURE;
        }

        $files = PostFiles::listing($root);
        $unpaired = PostFiles::unpaired($files);
        if ($unpaired) {
            foreach ($unpaired as $line) {
                $this->error($line);
            }

            return self::FAILURE;
        }

        if ($files['en'] === []) {
            $this->warn('No posts to push.');

            return self::SUCCESS;
        }

        // A file name that is not a slug could carry a `?` or a `/` into the
        // URL and change what a request does, so it stops the run before any.
        $badNames = array_filter($files['en'], fn (string $file): bool => ! preg_match(PostFiles::SLUG, basename($file, '.md')) || strlen($file) > 255);
        if ($badNames) {
            foreach ($badNames as $file) {
                $this->error("{$file} is not a usable slug. The file name must be lowercase letters, digits and single hyphens, such as my-post.md.");
            }

            return self::FAILURE;
        }

        $payloads = [];
        try {
            foreach ($files['en'] as $file) {
                $payloads[basename($file, '.md')] = $this->payload(PostFiles::readPair($root, $file));
            }
            $definitions = PostFiles::tagDefinitions($root);
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $client = Http::withToken($token)->acceptJson()->asJson()->timeout(30);
        $dryRun = (bool) $this->option('dry-run');

        try {
            // Tags come first, since a post can only use tags that exist.
            $pending = [];
            $failure = $this->prepareTags($client, $payloads, $definitions, $dryRun, $pending);
            if ($failure !== null) {
                return $failure;
            }

            // The check comes next, and a refusal stops the run before any post is written.
            $refused = false;
            foreach ($payloads as $slug => $payload) {
                $response = $this->send($client, "posts/{$slug}", $payload, dry: true);
                if ($response->status() === 401) {
                    return $this->tokenRefused();
                }
                if ($response->failed()) {
                    // In a dry run the tags that would be created do not exist
                    // yet, so a post that names one cannot pass its check. Every
                    // other slug was checked against the list already.
                    if ($dryRun && $pending !== [] && $response->json('code') === 'unknown_tag') {
                        $this->info("Would push {$slug} once its new tags exist");

                        continue;
                    }

                    $refused = true;
                    $this->reportRefusal($slug, $response);

                    continue;
                }
                if ($dryRun) {
                    $this->report($slug, $response, 'Would ');
                }
            }

            if ($refused) {
                $this->error('Nothing was pushed.');

                return self::FAILURE;
            }

            if ($dryRun) {
                $this->info('Dry run, nothing was written.');

                return self::SUCCESS;
            }

            foreach ($payloads as $slug => $payload) {
                $response = $this->send($client, "posts/{$slug}", $payload, dry: false);
                if ($response->status() === 401) {
                    return $this->tokenRefused();
                }
                if ($response->failed()) {
                    $this->reportRefusal($slug, $response);

                    return self::FAILURE;
                }
                $this->report($slug, $response, '');
            }
        } catch (ConnectionException $e) {
            $this->error('Could not reach '.$this->baseUrl().'. '.$e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * The request body for one post. The status is sent only with
     * `--publish`, so a push never takes a live post down or puts a draft
     * live by accident. The author line is not sent, because the writer is
     * the account behind the token. The date goes as RFC 3339 in UTC.
     *
     * @param  array{text: array<string, string>, published_at: ?Carbon, author: mixed, tags: ?list<string>}  $pair
     * @return array<string, mixed>
     */
    private function payload(array $pair): array
    {
        $payload = [];

        foreach (['en', 'id'] as $lang) {
            $payload[$lang] = [
                'title' => $pair['text']["title_{$lang}"],
                'description' => $pair['text']["description_{$lang}"],
                'body' => $pair['text']["body_{$lang}"],
            ];
        }

        if ($pair['published_at'] !== null) {
            $payload['published_at'] = $pair['published_at']->utc()->toIso8601ZuluString();
        }
        if ($pair['tags'] !== null) {
            $payload['tags'] = $pair['tags'];
        }
        if ($this->option('publish')) {
            $payload['status'] = 'published';
        }

        return $payload;
    }

    /**
     * Makes sure every tag the posts name exists, creating the ones the
     * folder defines. It checks the creations as a dry run first and then, on
     * a real run, makes them. `$pending` gets the slugs that are, or in a dry
     * run would be, created.
     *
     * @param  array<string, array<string, mixed>>  $payloads  By post slug.
     * @param  array<string, array{en: string, id: string}>  $definitions
     * @param  list<string>  $pending
     * @return ?int An exit code when the run has to stop, null when it can go on.
     *
     * @throws ConnectionException
     */
    private function prepareTags(PendingRequest $client, array $payloads, array $definitions, bool $dryRun, array &$pending): ?int
    {
        $wanted = [];
        foreach ($payloads as $payload) {
            array_push($wanted, ...($payload['tags'] ?? []));
        }
        $wanted = array_values(array_unique($wanted));
        if ($wanted === []) {
            return null;
        }

        $listed = (clone $client)->get($this->baseUrl().'/api/v1/tags');
        if ($listed->status() === 401) {
            return $this->tokenRefused();
        }
        if ($listed->failed()) {
            $this->error('Could not read the tags from the blog ('.$listed->status().').');

            return self::FAILURE;
        }

        $existing = array_map('strval', array_column((array) $listed->json('data'), 'slug'));
        $missing = array_values(array_diff($wanted, $existing));
        $undefined = array_values(array_diff($missing, array_keys($definitions)));
        if ($undefined !== []) {
            foreach ($undefined as $slug) {
                $this->error("No tag has the slug {$slug}, and tags.yml does not define it. Add it to tags.yml next to en/ and id/, or create it under Tags in the admin.");
            }
            $this->error('Nothing was pushed.');

            return self::FAILURE;
        }

        $refused = false;
        foreach ($missing as $slug) {
            $response = $this->send($client, "tags/{$slug}", $definitions[$slug], dry: true);
            if ($response->status() === 401) {
                return $this->tokenRefused();
            }
            if ($response->failed()) {
                $refused = true;
                $this->reportRefusal("tag {$slug}", $response);
            } elseif ($dryRun) {
                $this->info("Would create tag {$slug}");
            }
        }
        if ($refused) {
            $this->error('Nothing was pushed.');

            return self::FAILURE;
        }

        if (! $dryRun) {
            foreach ($missing as $slug) {
                $response = $this->send($client, "tags/{$slug}", $definitions[$slug], dry: false);
                if ($response->failed()) {
                    $this->reportRefusal("tag {$slug}", $response);

                    return self::FAILURE;
                }
                $this->info("Created tag {$slug}");
            }
        }

        $pending = $missing;

        return null;
    }

    /**
     * One PUT to the API. `$path` is `posts/<slug>` or `tags/<slug>`.
     *
     * @param  array<string, mixed>  $payload
     *
     * @throws ConnectionException
     */
    private function send(PendingRequest $client, string $path, array $payload, bool $dry): Response
    {
        // A request object keeps what is set on it, so each call starts from a copy.
        $request = clone $client;
        if ($dry) {
            $request->withQueryParameters(['dry_run' => 1]);
        }

        [$resource, $slug] = explode('/', $path, 2);

        return $request->put($this->baseUrl()."/api/v1/{$resource}/".rawurlencode($slug), $payload);
    }

    private function baseUrl(): string
    {
        return rtrim((string) config('services.blog_api.url'), '/');
    }

    private function tokenRefused(): int
    {
        $this->error('The blog refused the token. It may have expired or been revoked, so make a new one under API tokens on your profile page.');

        return self::FAILURE;
    }

    /** One line for a post the API accepted, in the words `posts:import` uses. */
    private function report(string $slug, Response $response, string $prefix): void
    {
        $action = $response->json('meta.action');
        $changes = (array) $response->json('meta.changes');

        $line = match ($action) {
            'created' => $prefix === '' ? "Created {$slug} (".$response->json('data.status').')' : "Would create {$slug} (".$response->json('data.status').')',
            'updated' => ($prefix === '' ? 'Updated ' : 'Would update ')."{$slug} (".implode(', ', $changes).')',
            default => "Unchanged {$slug}",
        };
        $this->info($line);

        foreach ((array) $response->json('meta.warnings') as $warning) {
            $this->warn("{$slug} {$warning}");
        }
    }

    private function reportRefusal(string $slug, Response $response): void
    {
        $this->error("Refused {$slug} ({$response->json('code', (string) $response->status())}): ".$response->json('message', 'The blog refused the post.'));

        foreach ((array) $response->json('errors') as $field => $messages) {
            foreach ((array) $messages as $message) {
                $this->line("  {$field}: {$message}");
            }
        }
    }
}
