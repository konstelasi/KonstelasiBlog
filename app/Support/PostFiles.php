<?php

namespace App\Support;

use App\Models\Post;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Spatie\YamlFrontMatter\YamlFrontMatter;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Reads a folder of posts kept as Markdown, one file per language with the
 * same name in `en/` and `id/`. Both `posts:import`, which writes the
 * database directly, and `posts:push`, which goes through the API, read their
 * input here, so a file means the same thing to each.
 */
final class PostFiles
{
    /** The limit of the post form's tag picker, so a file cannot ask for more than the admin allows. */
    public const MAX_TAGS = 6;

    /** What the admin and the API accept as a slug, for a post or a tag. */
    public const SLUG = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/';

    /**
     * The folder that holds `en/` and `id/`, tried as given and then relative
     * to this app. Null when neither has both.
     */
    public static function resolve(string $path): ?string
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
     * The tags a folder defines, from an optional `tags.yml` beside `en/` and
     * `id/`. Each slug maps to its two names, such as
     * `software-development: {en: Software development, id: Pengembangan software}`.
     * A tag is only created from a definition, so a typo in a post's `tags`
     * line still fails and never makes a tag.
     *
     * @return array<string, array{en: string, id: string}>
     *
     * @throws InvalidArgumentException When the file is not valid YAML, or an entry is not a slug with both names.
     */
    public static function tagDefinitions(string $root): array
    {
        $file = "{$root}/tags.yml";
        if (! is_file($file)) {
            return [];
        }

        try {
            $data = Yaml::parse((string) file_get_contents($file));
        } catch (ParseException $e) {
            throw new InvalidArgumentException('tags.yml is not valid YAML. '.$e->getMessage());
        }

        if ($data === null) {
            return [];
        }
        if (! is_array($data) || array_is_list($data)) {
            throw new InvalidArgumentException('tags.yml must map each tag slug to its English and Indonesian names.');
        }

        $definitions = [];
        foreach ($data as $slug => $names) {
            $slug = (string) $slug;
            if (preg_match(self::SLUG, $slug) !== 1) {
                throw new InvalidArgumentException("tags.yml has \"{$slug}\", which is not a slug. Use lowercase letters, digits and single hyphens.");
            }
            if (! is_array($names) || array_keys($names) !== ['en', 'id'] && array_keys($names) !== ['id', 'en']) {
                throw new InvalidArgumentException("tags.yml gives \"{$slug}\" something other than an English and an Indonesian name.");
            }
            foreach (['en', 'id'] as $lang) {
                if (! is_string($names[$lang]) || trim($names[$lang]) === '') {
                    throw new InvalidArgumentException("tags.yml has no {$lang} name for \"{$slug}\".");
                }
            }
            $definitions[$slug] = ['en' => trim($names['en']), 'id' => trim($names['id'])];
        }

        return $definitions;
    }

    /**
     * The file names in each language folder.
     *
     * @return array<string, list<string>>
     */
    public static function listing(string $root): array
    {
        $files = [];

        foreach (Post::LANGUAGES as $lang) {
            $files[$lang] = array_values(array_map('basename', glob("{$root}/{$lang}/*.md") ?: []));
        }

        return $files;
    }

    /**
     * One line for each file that has no twin in the other language.
     *
     * @param  array<string, list<string>>  $files  From `listing()`.
     * @return list<string>
     */
    public static function unpaired(array $files): array
    {
        return array_values(array_merge(
            array_map(fn ($f) => "en/{$f} has no id/{$f}", array_diff($files['en'], $files['id'])),
            array_map(fn ($f) => "id/{$f} has no en/{$f}", array_diff($files['id'], $files['en'])),
        ));
    }

    /**
     * Reads one post's two files. English carries the date, the author and
     * the tags, and Indonesian only fills them in when English leaves them
     * out. `tags` is null when neither file has the line, which is different
     * from an empty list, because the first leaves a draft's tags alone and
     * the second clears them.
     *
     * @param  bool  $withTags  Whether to read the `tags` line. The default import publishes and never reads tags, so a bad line only matters to a caller that does.
     * @return array{text: array<string, string>, published_at: ?Carbon, author: mixed, tags: ?list<string>}
     *
     * @throws InvalidArgumentException When a file has no title or no description, or a bad `tags` line.
     */
    public static function readPair(string $root, string $file, bool $withTags = true): array
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

            $publishedAt ??= self::date($doc->matter('published'));
            $author ??= $doc->matter('author');
            $tags ??= $withTags ? self::tagSlugs($doc->matter('tags'), "{$lang}/{$file}") : null;
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
    private static function tagSlugs(mixed $value, string $where): ?array
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

    /**
     * Symfony YAML reads an unquoted `2026-04-07` as a Unix timestamp, and a
     * quoted one as a string, so both are accepted. A date with no timezone
     * is read as UTC.
     */
    private static function date(mixed $value): ?Carbon
    {
        return match (true) {
            is_int($value) => Carbon::createFromTimestampUTC($value),
            $value instanceof DateTimeInterface => Carbon::instance($value),
            is_string($value) && $value !== '' => Carbon::parse($value, 'UTC'),
            default => null,
        };
    }
}
