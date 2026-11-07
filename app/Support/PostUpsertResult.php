<?php

namespace App\Support;

use App\Models\Post;

/**
 * What `PostUpsert` did, or in a dry run what it would do. The post is saved
 * unless it was a dry run, when it carries the values it would have saved.
 */
final class PostUpsertResult
{
    /**
     * @param  'created'|'updated'|'unchanged'  $action
     * @param  list<string>  $changes  The fields that differ from what was stored.
     * @param  list<string>  $tags  The tag slugs of the post after the write.
     * @param  list<string>  $warnings  House style problems in a draft, which the admin asks to fix on save.
     */
    public function __construct(
        public readonly Post $post,
        public readonly string $action,
        public readonly array $changes,
        public readonly array $tags,
        public readonly array $warnings,
        public readonly bool $dryRun,
        public readonly bool $siteRebuild,
    ) {}
}
