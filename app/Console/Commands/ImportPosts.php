<?php

namespace App\Console\Commands;

use App\Enums\PostStatus;
use App\Models\Post;
use App\Models\Tag;
use App\Models\User;
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
    {--dry-run : With --draft, print what each post would do and write nothing}
    {--writer= : With --draft, link new drafts and drafts with no writer to the account with this email}')]
#[Description('Import paired English and Indonesian Markdown posts as published posts, or as drafts with --draft')]
class ImportPosts extends Command
{
    /** The limit of the post form's tag picker, so a file cannot ask for more than the admin allows. */
    private const MAX_TAGS = 6;

    public function handle(): int
    {
        foreach (['include-live', 'dry-run', 'writer'] as $flag) {
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

            if ($pair['published_at']?->isFuture()) {
                $this->error("Refused {$slug}: its date has not come yet, and a post like that has to stay a draft. Import it with --draft, or fix the date.");

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
     * six text fields refreshed, and its date too when the English file has a
     * `published` line, so the file is where a date is planned. Without that
     * line the date stays as the admin left it. The same holds for tags: a
     * `tags` line lists tag slugs, a new draft gets them and an existing draft
     * is set to exactly them, and without the line its tags stay. A slug that
     * no tag has stops the whole run, because the import never creates a tag.
     * The author and writer set in the admin stay, except that `--writer`
     * links a new draft, and a draft that has no writer yet, to that account
     * and stores the account's name as its author text, as the admin's create
     * page does. A slug that is published stops the whole run before anything
     * is written, so no draft run changes a live page by accident. With `--include-live` a published post is refreshed
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

        $writer = null;
        if (filled($this->option('writer'))) {
            $email = (string) $this->option('writer');
            $writer = User::where('email', $email)->first();
            if ($writer === null) {
                $this->error("No account has the email {$email}. Nothing was imported.");

                return self::FAILURE;
            }
        }

        // Every slug the files ask for must belong to a tag already, because
        // creating one is a decision about the site's vocabulary that the admin
        // keeps to the people who manage tags.
        $wanted = array_values(array_unique(array_merge(...array_map(fn (array $pair) => $pair['tags'] ?? [], array_values($pairs)))));
        $tagIds = $wanted === [] ? [] : Tag::whereIn('slug', $wanted)->pluck('id', 'slug')->all();
        $unknown = array_diff($wanted, array_map('strval', array_keys($tagIds)));
        if ($unknown) {
            foreach ($unknown as $slug) {
                $this->error("No tag has the slug {$slug}. Create it under Tags first. Nothing was imported.");
            }

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
            $wantedIds = $pair['tags'] === null ? null : array_map(fn (string $tag) => (int) $tagIds[$tag], $pair['tags']);
            if ($post === null) {
                if (! $dryRun) {
                    $created = Post::create([
                        'slug' => $slug,
                        'status' => PostStatus::Draft,
                        ...$pair['text'],
                        'published_at' => $pair['published_at'],
                        'author' => $writer?->name ?? (filled($pair['author']) ? $pair['author'] : Post::DEFAULT_AUTHOR),
                        'user_id' => $writer?->getKey(),
                    ]);
                    if ($wantedIds) {
                        $created->tags()->sync($wantedIds);
                    }
                }
                $this->info(($dryRun ? 'Would create ' : 'Created ')."{$slug} (draft)");

                continue;
            }

            $post->fill($pair['text']);
            $tagsChanged = false;
            // A live post keeps its date, writer and tags, because each changes what the public site shows.
            if ($post->status === PostStatus::Draft) {
                if ($pair['published_at'] !== null) {
                    $post->published_at = $pair['published_at'];
                }
                if ($writer !== null && $post->user_id === null) {
                    $post->user_id = $writer->getKey();
                    $post->author = $writer->name;
                }
                if ($wantedIds !== null) {
                    $current = $post->tags()->pluck('tags.id')->map(fn ($id) => (int) $id)->all();
                    sort($current);
                    $target = array_unique($wantedIds);
                    sort($target);
                    $tagsChanged = $current !== $target;
                }
            }
            $changed = array_keys($post->getDirty());
            if ($tagsChanged) {
                $changed[] = 'tags';
            }
            if ($changed === []) {
                $this->info("Unchanged {$slug}");

                continue;
            }

            if (! $dryRun) {
                // A save with nothing dirty still fires `saved`, so a tags-only change skips it.
                if ($post->isDirty()) {
                    $post->save();
                }
                if ($tagsChanged) {
                    $post->tags()->sync($wantedIds);
                }
            }
            $this->info(($dryRun ? 'Would refresh ' : 'Refreshed ').($post->status === PostStatus::Published ? 'live post ' : '')."{$slug} (".implode(', ', $changed).')');
        }

        if ($dryRun) {
            $this->info('Dry run, nothing was written.');
        }

        return self::SUCCESS;
    }

    /**
     * Reads one post's two files. English carries the date, the author and
     * the tags, and Indonesian only fills them in when English leaves them
     * out. `tags` is null when neither file has the line, which is different
     * from an empty list, because the first leaves a draft's tags alone and
     * the second clears them.
     *
     * @return array{text: array<string, string>, published_at: ?Carbon, author: mixed, tags: ?list<string>}
     *
     * @throws InvalidArgumentException When a file has no title or no description, or a bad `tags` line.
     */
    private function readPair(string $root, string $file): array
    {
        $text = [];
        $publishedAt = null;
        $author = null;
        $tags = null;

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
            // The default import publishes and never reads tags, so a bad line only matters with --draft.
            $tags ??= $this->option('draft') ? $this->tagSlugs($doc->matter('tags'), "{$lang}/{$file}") : null;
        }

        return ['text' => $text, 'published_at' => $publishedAt, 'author' => $author, 'tags' => $tags];
    }

    /**
     * A `tags` line is a YAML list of tag slugs, such as `tags: [mysql, stardust]`.
     * A repeated slug counts once.
     *
     * @return ?list<string> Null when the file has no `tags` line.
     *
     * @throws InvalidArgumentException When the line is not a list of slugs, or lists more than the post form allows.
     */
    private function tagSlugs(mixed $value, string $where): ?array
    {
        if ($value === null) {
            return null;
        }

        if (! is_array($value) || ! array_is_list($value)) {
            throw new InvalidArgumentException("{$where} has a tags line that is not a list of tag slugs.");
        }

        $slugs = [];
        foreach ($value as $slug) {
            if (! is_string($slug) || trim($slug) === '') {
                throw new InvalidArgumentException("{$where} has a tags line with an entry that is not a tag slug.");
            }
            $slugs[] = trim($slug);
        }
        $slugs = array_values(array_unique($slugs));

        if (count($slugs) > self::MAX_TAGS) {
            throw new InvalidArgumentException("{$where} lists ".count($slugs).' tags, and a post takes at most '.self::MAX_TAGS.'.');
        }

        return $slugs;
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
