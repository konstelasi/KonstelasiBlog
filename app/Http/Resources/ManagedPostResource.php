<?php

namespace App\Http\Resources;

use App\Filament\Resources\Posts\PostResource as AdminPostResource;
use App\Models\Post;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One post as the authoring API returns it, drafts included and in full. It
 * is a different shape from `PostResource`, which is the published post the
 * Astro build reads and must not change. This one is for the people and
 * tools that write posts.
 *
 * @mixin Post
 */
class ManagedPostResource extends JsonResource
{
    /** @var list<string> */
    private array $tagSlugs = [];

    /**
     * The tag slugs to show. A dry run has not saved the tags, so the caller
     * says which ones the post would have.
     *
     * @param  list<string>  $slugs
     */
    public function withTags(array $slugs): static
    {
        $this->tagSlugs = $slugs;

        return $this;
    }

    public function toArray(Request $request): array
    {
        $saved = $this->exists;
        $published = $this->status->value === 'published';

        $post = [
            'slug' => $this->slug,
            'status' => $this->status->value,
            'published_at' => $this->published_at?->utc()->toIso8601ZuluString(),
            'author' => $this->byline(),
            'writer' => $this->writer ? ['id' => $this->writer->getKey(), 'name' => $this->writer->name] : null,
            'tags' => $this->tagSlugs,
            'missing_languages' => $this->missingLanguages(),
            'urls' => $published ? ['en' => $this->publicUrl('en'), 'id' => $this->publicUrl('id')] : null,
            'admin_url' => $saved ? AdminPostResource::getUrl('edit', ['record' => $this->resource], panel: 'admin') : null,
            'created_at' => $this->created_at?->utc()->toIso8601ZuluString(),
            'updated_at' => $this->updated_at?->utc()->toIso8601ZuluString(),
        ];

        foreach (Post::LANGUAGES as $lang) {
            $post[$lang] = [
                'title' => $this->{"title_{$lang}"},
                'description' => $this->{"description_{$lang}"},
                'body' => $this->{"body_{$lang}"},
            ];
        }

        return $post;
    }
}
