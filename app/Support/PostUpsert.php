<?php

namespace App\Support;

use App\Enums\ApiAbility;
use App\Enums\PostStatus;
use App\Models\Post;
use App\Models\Tag;
use App\Models\User;
use App\Rules\HouseStyle;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Creates or updates one post for the authoring API, from the account behind
 * a token. It holds every rule of the write in one place so the controller
 * stays thin and a test can call it without HTTP.
 *
 * A write is checked in this order. The account must be allowed to do it
 * (`PostPolicy`), the token must have the ability for it, and the post that
 * would result must be fit to be what it claims. A draft may be incomplete,
 * and its style problems come back as warnings, as the admin asks to fix
 * them on save. A published post may not carry a date still to come, must be
 * complete in both languages and must pass the house style.
 *
 * Nothing is written until every check has passed, and a dry run stops there.
 */
final class PostUpsert
{
    /**
     * @param  array<string, mixed>  $input  Already validated: `status`, `published_at`, `tags`, `en`, `id`, each optional. A field that is left out keeps its stored value.
     *
     * @throws PostRefused
     */
    public function handle(User $user, string $slug, array $input, bool $dryRun = false): PostUpsertResult
    {
        $post = Post::with('tags')->where('slug', $slug)->first();
        $creating = $post === null;
        $post ??= new Post(['slug' => $slug]);

        $this->authorise($user, $post, $creating, $input);

        $storedTags = $creating ? [] : $post->tags->pluck('slug')->sort()->values()->all();
        $attributes = $this->attributes($input);
        $post->fill($attributes);

        if ($post->status === PostStatus::Published) {
            $this->requirePublishable($post);
        }
        $warnings = $post->status === PostStatus::Draft ? $this->styleWarnings($post) : [];

        $tagIds = array_key_exists('tags', $input) ? $this->tagIds($input['tags']) : null;
        $tags = $tagIds === null ? $storedTags : collect($input['tags'])->unique()->sort()->values()->all();

        // A new model counts every default as changed, so a creation reports
        // only the fields that were sent.
        $changes = $creating ? array_keys($attributes) : array_keys($post->getDirty());
        if ($tagIds !== null && $tags !== $storedTags) {
            $changes[] = 'tags';
        }
        $action = $creating ? 'created' : ($changes === [] ? 'unchanged' : 'updated');

        if ($dryRun || $action === 'unchanged') {
            return new PostUpsertResult($post, $action, $changes, $tags, $warnings, $dryRun, false);
        }

        DB::transaction(function () use ($post, $creating, $user, $tagIds, $tags, $storedTags): void {
            if ($creating) {
                // As the admin's create page does, the account that writes it
                // is the writer, and its name is kept as the byline to fall back on.
                $post->user_id = $user->getKey();
                $post->author = $user->name;
            }
            if ($post->isDirty()) {
                $post->save();
            }
            if ($tagIds !== null && $tags !== $storedTags) {
                $post->tags()->sync($tagIds);
            }
        });

        return new PostUpsertResult(
            $post,
            $action,
            $changes,
            $tags,
            $warnings,
            false,
            filled(config('services.github.token')) && $post->affectsPublicSite(),
        );
    }

    /**
     * The house style problems of every language field, by field, such as
     * `en.title`. A field that is empty has none.
     *
     * @return array<string, list<string>>
     */
    public static function styleProblems(Post $post): array
    {
        $problems = [];

        foreach (Post::LANGUAGES as $lang) {
            foreach (Post::LANGUAGE_FIELDS as $field) {
                $value = (string) $post->{"{$field}_{$lang}"};
                $kind = match ($field) {
                    'title' => HouseStyle::TITLE,
                    'body' => HouseStyle::MARKDOWN,
                    default => HouseStyle::TEXT,
                };
                $found = HouseStyle::problems($value, $kind);
                if ($found !== []) {
                    $problems["{$lang}.{$field}"] = $found;
                }
            }
        }

        return $problems;
    }

    /**
     * Who may do this. Three separate questions, so a refusal says which one
     * it was. May the account touch this post at all, may the token touch a
     * live post, and may the account set a status or a date.
     *
     * @param  array<string, mixed>  $input
     *
     * @throws PostRefused
     */
    private function authorise(User $user, Post $post, bool $creating, array $input): void
    {
        $gate = Gate::forUser($user);

        if ($creating ? $gate->denies('create', Post::class) : $gate->denies('update', $post)) {
            throw new PostRefused(
                'not_allowed',
                $creating ? 'Your account may not start posts.' : 'Your account may not edit this post.',
                status: 403,
            );
        }

        $storedLive = ! $creating && $post->status === PostStatus::Published;
        $wantsLive = ($input['status'] ?? null) === PostStatus::Published->value;

        if (($storedLive || $wantsLive) && $user->tokenCant(ApiAbility::PostsPublish->value)) {
            throw new PostRefused(
                'ability_missing',
                'This token may not publish or edit live posts. Create one with the Publish ability.',
                status: 403,
            );
        }

        $newStatus = $input['status'] ?? null;
        $changesStatus = $newStatus !== null && $newStatus !== ($creating ? PostStatus::Draft->value : $post->status->value);

        if (($changesStatus || array_key_exists('published_at', $input)) && $gate->denies('publish', Post::class)) {
            throw new PostRefused(
                'not_allowed',
                'Your account may not publish, take a post down or set a date. Ask an Editor or an Admin.',
                status: 403,
            );
        }
    }

    /**
     * The columns the input sets. `published_at` arrives as RFC 3339 and is
     * stored in UTC, whatever offset it was written with.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function attributes(array $input): array
    {
        $attributes = [];

        foreach (Post::LANGUAGES as $lang) {
            foreach (Post::LANGUAGE_FIELDS as $field) {
                if (array_key_exists($field, $input[$lang] ?? [])) {
                    $attributes["{$field}_{$lang}"] = $input[$lang][$field];
                }
            }
        }

        if (array_key_exists('status', $input)) {
            $attributes['status'] = PostStatus::from($input['status']);
        }

        if (array_key_exists('published_at', $input)) {
            $attributes['published_at'] = $input['published_at'] === null
                ? null
                : Carbon::parse($input['published_at'])->utc();
        }

        return $attributes;
    }

    /**
     * The checks that only a post going live has to pass, on the post as it
     * would be stored.
     *
     * @throws PostRefused
     */
    private function requirePublishable(Post $post): void
    {
        if ($post->published_at?->isFuture()) {
            throw new PostRefused(
                'future_date_requires_draft',
                Post::FUTURE_DATE_MESSAGE,
                ['published_at' => [Post::FUTURE_DATE_MESSAGE]],
            );
        }

        $missing = $post->missingLanguages();
        if ($missing !== []) {
            $message = 'A published post needs a title, a description and a body in English and in Indonesian. Missing in '.implode(' and ', $missing).'.';

            throw new PostRefused('language_incomplete', $message, ['status' => [$message]]);
        }

        $problems = self::styleProblems($post);
        if ($problems !== []) {
            throw new PostRefused(
                'house_style',
                'The text breaks the house writing style. A draft may keep it, a published post may not.',
                $problems,
            );
        }
    }

    /**
     * @return list<string>
     */
    private function styleWarnings(Post $post): array
    {
        $warnings = [];

        foreach (self::styleProblems($post) as $field => $problems) {
            foreach ($problems as $problem) {
                $warnings[] = "{$field}: {$problem}";
            }
        }

        return $warnings;
    }

    /**
     * The ids of the tags with these slugs. The API never creates a tag,
     * because that is a choice about the site's vocabulary that only a person
     * with `tag.manage` makes.
     *
     * @param  list<string>  $slugs
     * @return list<int>
     *
     * @throws PostRefused
     */
    private function tagIds(array $slugs): array
    {
        $slugs = array_values(array_unique($slugs));
        $found = $slugs === [] ? [] : Tag::whereIn('slug', $slugs)->pluck('id', 'slug')->all();
        $unknown = array_values(array_diff($slugs, array_map('strval', array_keys($found))));

        if ($unknown !== []) {
            $messages = array_map(fn (string $slug): string => "No tag has the slug {$slug}. Create it under Tags first.", $unknown);

            throw new PostRefused('unknown_tag', $messages[0], ['tags' => $messages]);
        }

        return array_map(fn (string $slug): int => (int) $found[$slug], $slugs);
    }
}
