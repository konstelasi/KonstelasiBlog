<?php

namespace App\Observers;

use App\Jobs\RebuildSite;
use App\Models\Tag;

/**
 * A tag's names and slug are printed on every published post that carries
 * it, and its page lists them. So a change to a tag that sits on a live post
 * starts a rebuild, and a tag nobody uses yet starts nothing.
 */
class TagObserver
{
    public function saved(Tag $tag): void
    {
        if ($tag->isOnPublicSite()) {
            RebuildSite::dispatchAfterResponse();
        }
    }

    /** Checked before the delete, because removing the tag also removes its links to posts. */
    public function deleting(Tag $tag): void
    {
        if ($tag->isOnPublicSite()) {
            RebuildSite::dispatchAfterResponse();
        }
    }
}
