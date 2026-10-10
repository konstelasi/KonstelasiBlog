<?php

namespace App\Http\Resources;

use App\Models\Post;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One post as the Astro build reads it. The `en` and `id` objects become
 * the `en/<slug>` and `id/<slug>` entries of the site's blog collection.
 *
 * @mixin Post
 */
class PostResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $post = [
            'slug' => $this->slug,
            'published_at' => $this->published_at?->toIso8601String(),
            'author' => $this->byline(),
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
