<?php

namespace App\Console\Commands;

use App\Enums\PostStatus;
use App\Models\Post;
use App\Rules\HouseStyle;
use DateTimeInterface;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Spatie\YamlFrontMatter\YamlFrontMatter;

/**
 * Reads the posts that the Astro site kept as Markdown, one file per
 * language with the same name in `en/` and `id/`, and stores each pair as
 * one published post. Running it again updates the same rows, matched by
 * slug, so it is safe to repeat. With `--draft` it never publishes, see
 * `importDrafts()`.
 */
#[Signature('posts:import
    {path=../src/content/blog : Folder that holds the en/ and id/ folders, relative to this app}
    {--draft : Save new posts as drafts and refresh the text of existing drafts, never publishing}
    {--include-live : With --draft, also refresh the text of posts that are already published}
    {--dry-run : With --draft, print what each post would do and write nothing}')]
#[Description('Import paired English and Indonesian Markdown posts as published posts, or as drafts with --draft')]
class ImportPosts extends Command
{
    public function handle(): int
    {
        foreach (['include-live', 'dry-run'] as $flag) {
            if ($this->option($flag) && ! $this->option('draft')) {
                $this->error("--{$flag} only works together with --draft.");

                return self::FAILURE;
            }
        }

        $root = $this->resolve($this->argument('path'));
        if ($root === null) {
            $this->error("No en/ and id/ folders found under {$this->argument('path')}.");

            return self::FAILURE;
        }

        $files = [];
        foreach (Post::LANGUAGES as $lang) {
            $files[$lang] = array_map('basename', glob("{$root}/{$lang}/*.md") ?: []);
        }

        $unpaired = array_merge(
            array_map(fn ($f) => "en/{$f} has no id/{$f}", array_diff($files['en'], $files['id'])),
            array_map(fn ($f) => "id/{$f} has no en/{$f}", array_diff($files['id'], $files['en'])),
        );
        if ($unpaired) {
            foreach ($unpaired as $line) {
                $this->error($line);
            }

            return self::FAILURE;
        }

        if ($files['en'] === []) {
            $this->warn('No posts to import.');

            return self::SUCCESS;
        }

        if ($this->option('draft')) {
            return $this->importDrafts($root, $files['en']);
        }

        foreach ($files['en'] as $file) {
            $slug = basename($file, '.md');

            try {
                $pair = $this->readPair($root, $file);
            } catch (InvalidArgumentException $e) {
                $this->error($e->getMessage());

                return self::FAILURE;
            }

            $fields = [
                'status' => PostStatus::Published,
                ...$pair['text'],
                'published_at' => $pair['published_at'],
                'author' => filled($pair['author']) ? $pair['author'] : Post::DEFAULT_AUTHOR,
            ];

            $this->warnAboutStyle($slug, $fields);

            $post = Post::updateOrCreate(['slug' => $slug], $fields);
            $this->info(($post->wasRecentlyCreated ? 'Created ' : 'Updated ').$slug);
        }

        return self::SUCCESS;
    }

    /**
     * The `--draft` run. A pair with no post yet becomes a draft, seeded with
     * the date and author in its files. A pair whose slug is a draft has its
     * six text fields refreshed and nothing else, so the date, author, tags
     * and writer set in the admin stay. A slug that is published stops the
     * whole run before anything is written, so no draft run changes a live
     * page by accident. With `--include-live` a published post is refreshed
     * the same way, keeps its status and date, and the observer starts the
     * site rebuild when its text really changed. With `--dry-run` the run
     * decides everything the same way, refusals included, and writes nothing.
     *
     * @param  list<string>  $files  The file names in `en/`, each `<slug>.md`.
     */
    private function importDrafts(string $root, array $files): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $pairs = [];
        $existing = [];

        try {
            foreach ($files as $file) {
                $slug = basename($file, '.md');
                $pairs[$slug] = $this->readPair($root, $file);
                $existing[$slug] = Post::where('slug', $slug)->first();
            }
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $live = array_keys(array_filter($existing, fn (?Post $post) => $post?->status === PostStatus::Published));
        if ($live && ! $this->option('include-live')) {
            foreach ($live as $slug) {
                $this->error("Refused {$slug}: it is published. Add --include-live to refresh a live post. Nothing was imported.");
            }

            return self::FAILURE;
        }

        foreach ($pairs as $slug => $pair) {
            $this->warnAboutStyle($slug, $pair['text']);

            $post = $existing[$slug];
            if ($post === null) {
                if (! $dryRun) {
                    Post::create([
                        'slug' => $slug,
                        'status' => PostStatus::Draft,
                        ...$pair['text'],
                        'published_at' => $pair['published_at'],
                        'author' => filled($pair['author']) ? $pair['author'] : Post::DEFAULT_AUTHOR,
                    ]);
                }
                $this->info(($dryRun ? 'Would create ' : 'Created ')."{$slug} (draft)");

                continue;
            }

            $post->fill($pair['text']);
            $changed = array_keys($post->getDirty());
            if ($changed === []) {
                $this->info("Unchanged {$slug}");

                continue;
            }

            if (! $dryRun) {
                $post->save();
            }
            $this->info(($dryRun ? 'Would refresh ' : 'Refreshed ').($post->status === PostStatus::Published ? 'live post ' : '')."{$slug} (".implode(', ', $changed).')');
        }

        if ($dryRun) {
            $this->info('Dry run, nothing was written.');
        }

        return self::SUCCESS;
    }

    /**
     * Reads one post's two files. English carries the date and the author,
     * and Indonesian only fills them in when English leaves them out.
     *
     * @return array{text: array<string, string>, published_at: ?Carbon, author: mixed}
     *
     * @throws InvalidArgumentException When a file has no title or no description.
     */
    private function readPair(string $root, string $file): array
    {
        $text = [];
        $publishedAt = null;
        $author = null;

        foreach (Post::LANGUAGES as $lang) {
            $doc = YamlFrontMatter::parseFile("{$root}/{$lang}/{$file}");

            foreach (['title', 'description'] as $key) {
                if (blank($doc->matter($key))) {
                    throw new InvalidArgumentException("{$lang}/{$file} has no {$key}.");
                }
            }

            $text["title_{$lang}"] = trim($doc->matter('title'));
            $text["description_{$lang}"] = trim($doc->matter('description'));
            $text["body_{$lang}"] = trim(str_replace("\r\n", "\n", $doc->body()), "\n")."\n";

            $publishedAt ??= $this->date($doc->matter('published'));
            $author ??= $doc->matter('author');
        }

        return ['text' => $text, 'published_at' => $publishedAt, 'author' => $author];
    }

    private function resolve(string $path): ?string
    {
        foreach ([$path, base_path($path)] as $candidate) {
            $real = realpath($candidate);
            if ($real !== false && is_dir("{$real}/en") && is_dir("{$real}/id")) {
                return $real;
            }
        }

        return null;
    }

    /**
     * Symfony YAML reads an unquoted `2026-04-07` as a Unix timestamp, and a
     * quoted one as a string, so both are accepted.
     */
    private function date(mixed $value): ?Carbon
    {
        return match (true) {
            is_int($value) => Carbon::createFromTimestampUTC($value),
            $value instanceof DateTimeInterface => Carbon::instance($value),
            is_string($value) && $value !== '' => Carbon::parse($value, 'UTC'),
            default => null,
        };
    }

    /**
     * The admin refuses text that breaks the house style. The import keeps
     * the text as it is but says what the admin will ask to change.
     *
     * @param  array<string, mixed>  $fields
     */
    private function warnAboutStyle(string $slug, array $fields): void
    {
        foreach (Post::languageColumns() as $column) {
            $kind = match (strtok($column, '_')) {
                'title' => HouseStyle::TITLE,
                'body' => HouseStyle::MARKDOWN,
                default => HouseStyle::TEXT,
            };
            foreach (HouseStyle::problems((string) $fields[$column], $kind) as $problem) {
                $this->warn("{$slug} {$column}: {$problem}");
            }
        }
    }
}
