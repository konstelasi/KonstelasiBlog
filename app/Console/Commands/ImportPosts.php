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
use Spatie\YamlFrontMatter\YamlFrontMatter;

/**
 * Reads the posts that the Astro site kept as Markdown, one file per
 * language with the same name in `en/` and `id/`, and stores each pair as
 * one published post. Running it again updates the same rows, matched by
 * slug, so it is safe to repeat.
 */
#[Signature('posts:import {path=../src/content/blog : Folder that holds the en/ and id/ folders, relative to this app}')]
#[Description('Import paired English and Indonesian Markdown posts as published posts')]
class ImportPosts extends Command
{
    public function handle(): int
    {
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

        foreach ($files['en'] as $file) {
            $slug = basename($file, '.md');
            $fields = ['status' => PostStatus::Published];

            foreach (Post::LANGUAGES as $lang) {
                $doc = YamlFrontMatter::parseFile("{$root}/{$lang}/{$file}");

                foreach (['title', 'description'] as $key) {
                    if (blank($doc->matter($key))) {
                        $this->error("{$lang}/{$file} has no {$key}.");

                        return self::FAILURE;
                    }
                }

                $fields["title_{$lang}"] = trim($doc->matter('title'));
                $fields["description_{$lang}"] = trim($doc->matter('description'));
                $fields["body_{$lang}"] = trim(str_replace("\r\n", "\n", $doc->body()), "\n")."\n";

                // English carries the date and the author. Indonesian only
                // fills them in when English leaves them out.
                $fields['published_at'] ??= $this->date($doc->matter('published'));
                $fields['author'] ??= $doc->matter('author');
            }

            $fields['author'] = filled($fields['author']) ? $fields['author'] : Post::DEFAULT_AUTHOR;

            $this->warnAboutStyle($slug, $fields);

            $post = Post::updateOrCreate(['slug' => $slug], $fields);
            $this->info(($post->wasRecentlyCreated ? 'Created ' : 'Updated ').$slug);
        }

        return self::SUCCESS;
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
