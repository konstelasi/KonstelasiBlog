<?php

namespace App\Support;

use App\Enums\PostStatus;
use App\Models\Post;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * What the dashboard says about the whole blog and, for the people who may
 * act on it, about the team. It is a plain class so every figure can be
 * tested without a page. The profile page's `ProfileOverview` is the same
 * idea for one account's own work.
 */
class DashboardOverview
{
    /** How many rows each list on the page shows at most. */
    private const LIMIT = 8;

    /** How many months the publishing chart covers, this one included. */
    public const MONTHS = 12;

    public function __construct(private readonly User $user) {}

    public function live(): int
    {
        return Post::query()->published()->count();
    }

    public function drafts(): int
    {
        return Post::query()->where('status', PostStatus::Draft)->count();
    }

    /** Words in both languages of the live posts, which is what readers can read. */
    public function liveWords(): int
    {
        return Post::query()->published()->get(['body_en', 'body_id'])
            ->sum(fn (Post $post): int => Post::wordCount($post->body_en) + Post::wordCount($post->body_id));
    }

    public function lastPublished(): ?CarbonInterface
    {
        return Post::query()->published()->orderByDesc('published_at')->first(['published_at'])?->published_at;
    }

    /**
     * Live posts per month for the last twelve months, oldest first, with a
     * zero for a month that had none. The keys are `Y-m`. Grouped here and
     * not in SQL, so the same code runs on SQLite and MariaDB.
     *
     * @return array<string, int>
     */
    public function monthlyPublished(): array
    {
        $first = now()->startOfMonth()->subMonths(self::MONTHS - 1);

        $months = [];
        for ($i = 0; $i < self::MONTHS; $i++) {
            $months[$first->copy()->addMonths($i)->format('Y-m')] = 0;
        }

        Post::query()->published()->where('published_at', '>=', $first)->pluck('published_at')
            ->each(function (CarbonInterface $at) use (&$months): void {
                $key = $at->format('Y-m');

                if (array_key_exists($key, $months)) {
                    $months[$key]++;
                }
            });

        return $months;
    }

    /** Whether this account may publish, which is what the lists below are for. */
    public function isPublisher(): bool
    {
        return $this->user->can(Rbac::POST_PUBLISH);
    }

    /**
     * Drafts with both languages complete, the one waiting longest first.
     * Unlike the profile page's list this includes the viewer's own drafts,
     * since a publisher's own draft waits for a decision too.
     *
     * @return Collection<int, Post>
     */
    public function readyToPublish(): Collection
    {
        if (! $this->isPublisher()) {
            return collect();
        }

        return Post::query()->where('status', PostStatus::Draft)->oldest('updated_at')->get()
            ->filter(fn (Post $post): bool => $post->missingLanguages() === [])
            ->take(self::LIMIT)
            ->values();
    }

    /**
     * Drafts that miss a language, with the languages that are not complete
     * yet. A publisher sees everyone's, anyone else only their own.
     *
     * @return Collection<int, array{post: Post, missing: list<string>}>
     */
    public function halfDone(): Collection
    {
        $query = Post::query()->with('writer')->where('status', PostStatus::Draft)->oldest('updated_at');

        if (! $this->isPublisher()) {
            $query->where('user_id', $this->user->getKey());
        }

        return $query->get()
            ->map(fn (Post $post): array => ['post' => $post, 'missing' => $post->missingLanguages()])
            ->filter(fn (array $row): bool => $row['missing'] !== [])
            ->take(self::LIMIT)
            ->values();
    }

    /** Whether this account manages accounts, which is what the two-factor list is for. */
    public function managesAccounts(): bool
    {
        return $this->user->can(Rbac::USER_MANAGE);
    }

    /** Accounts that can sign in to the admin. */
    public function accountCount(): int
    {
        return User::role(Rbac::ROLES)->count();
    }

    /**
     * Accounts that can sign in and have not turned on two-factor.
     *
     * @return Collection<int, User>
     */
    public function withoutTwoFactor(): Collection
    {
        if (! $this->managesAccounts()) {
            return collect();
        }

        return User::role(Rbac::ROLES)->with('roles')->whereNull('app_authentication_secret')->orderBy('name')->get();
    }
}
