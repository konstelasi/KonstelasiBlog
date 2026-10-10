<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The browsers an account is signed in on, read from the `sessions` table,
 * and the way to end all but the one in use. It works only while sessions are
 * kept in the database, which is what the host does. With any other driver
 * `isAvailable()` is false and the page leaves the section out, instead of
 * showing a list that would be wrong.
 */
class BrowserSessions
{
    public function __construct(
        private readonly User $user,
        private readonly string $currentId,
    ) {}

    public static function isAvailable(): bool
    {
        return config('session.driver') === 'database';
    }

    /** @return Builder */
    private function table()
    {
        return DB::connection(config('session.connection'))->table(config('session.table', 'sessions'));
    }

    /**
     * Newest first. A session older than the session lifetime is already
     * dead, so it is left out.
     *
     * @return Collection<int, array{id: string, description: string, ip: ?string, lastActive: Carbon, current: bool}>
     */
    public function all(): Collection
    {
        $oldest = now()->subMinutes((int) config('session.lifetime'))->getTimestamp();

        return $this->table()
            ->where('user_id', $this->user->getKey())
            ->where('last_activity', '>=', $oldest)
            ->orderByDesc('last_activity')
            ->get()
            ->map(fn (object $row): array => [
                'id' => $row->id,
                'description' => UserAgent::describe($row->user_agent),
                'ip' => $row->ip_address,
                'lastActive' => Carbon::createFromTimestamp($row->last_activity),
                'current' => $row->id === $this->currentId,
            ]);
    }

    public function hasOthers(): bool
    {
        return $this->all()->contains(fn (array $session): bool => ! $session['current']);
    }

    /** Ends every session of this account except the one in use. Returns how many ended. */
    public function signOutOthers(): int
    {
        return $this->table()
            ->where('user_id', $this->user->getKey())
            ->where('id', '!=', $this->currentId)
            ->delete();
    }
}
