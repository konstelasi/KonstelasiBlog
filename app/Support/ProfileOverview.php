<?php

namespace App\Support;

use App\Enums\PostStatus;
use App\Models\Post;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * What the Overview tab of the profile page says about one account's work.
 * It is a plain class so every figure can be tested without a page.
 */
class ProfileOverview
{
    /** How many rows each list on the page shows at most. */
    private const LIMIT = 8;

    public function __construct(private readonly User $user) {}

    /** @return Builder<Post> */
    private function own(): Builder
    {
        return Post::query()->where('user_id', $this->user->getKey());
    }

    public function drafts(): int
    {
        return $this->own()->where('status', PostStatus::Draft)->count();
    }

    public function live(): int
    {
        return $this->own()->published()->count();
    }

    /** Words in both languages of every post the account wrote, drafts included. */
    public function words(): int
    {
        return $this->own()->get(['body_en', 'body_id'])
            ->sum(fn (Post $post): int => Post::wordCount($post->body_en) + Post::wordCount($post->body_id));
    }

    /**
     * The account's own drafts that are not ready to publish, with the
     * languages that are not complete yet.
     *
     * @return Collection<int, array{post: Post, missing: list<string>}>
     */
    public function incompleteDrafts(): Collection
    {
        return $this->own()->where('status', PostStatus::Draft)->latest('updated_at')->get()
            ->map(fn (Post $post): array => ['post' => $post, 'missing' => $post->missingLanguages()])
            ->filter(fn (array $row): bool => $row['missing'] !== [])
            ->take(self::LIMIT)
            ->values();
    }

    /**
     * Drafts by other people, or imported ones, that have both languages
     * complete. Only someone who can publish has a use for the list.
     *
     * @return Collection<int, Post>
     */
    public function readyToPublish(): Collection
    {
        if ($this->user->cannot(Rbac::POST_PUBLISH)) {
            return collect();
        }

        return Post::query()
            ->where('status', PostStatus::Draft)
            ->where(fn (Builder $query) => $query->whereNull('user_id')->orWhere('user_id', '!=', $this->user->getKey()))
            ->latest('updated_at')
            ->get()
            ->filter(fn (Post $post): bool => $post->missingLanguages() === [])
            ->take(self::LIMIT)
            ->values();
    }

    public function roleName(): string
    {
        return Str::ucfirst((string) $this->user->roles->first()?->name);
    }

    /**
     * What the account's role lets it do, from what it can really do.
     *
     * @return list<string>
     */
    public function abilities(): array
    {
        return collect(Rbac::SENTENCES)
            ->filter(fn (string $sentence, string $permission): bool => $this->user->can($permission))
            ->values()
            ->all();
    }

    /** The account's most recently edited posts, for the table on the Overview tab. */
    public function posts(): Collection
    {
        return $this->own()->latest('updated_at')->limit(self::LIMIT)->get();
    }

    /**
     * Every ability, grouped for the page, with whether this account has it.
     * The ones it lacks are listed too, so a Writer can see what an Editor adds.
     *
     * @return array<string, list<array{sentence: string, allowed: bool}>>
     */
    public function abilityGroups(): array
    {
        $groups = ['Posts' => [], 'Accounts' => []];

        foreach (Rbac::SENTENCES as $permission => $sentence) {
            $group = str_starts_with($permission, 'user.') ? 'Accounts' : 'Posts';

            $groups[$group][] = ['sentence' => $sentence, 'allowed' => $this->user->can($permission)];
        }

        return $groups;
    }

    /** One line on what the role is for. */
    public function roleLead(): string
    {
        return match ($this->user->roles->first()?->name) {
            Rbac::WRITER => 'A Writer starts posts and finishes their own drafts. Publishing is for an Editor.',
            Rbac::EDITOR => 'An Editor edits and publishes any post. Accounts are for an Admin.',
            Rbac::ADMIN => 'An Admin can do everything an Editor can, and manages accounts too.',
            default => '',
        };
    }

    /**
     * The action to offer next to a draft that is not ready.
     *
     * @param  list<string>  $missing
     */
    public static function nextStep(array $missing): string
    {
        return count($missing) === 1 ? 'Finish '.$missing[0] : 'Open draft';
    }
}
