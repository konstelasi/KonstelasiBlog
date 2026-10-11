<?php

namespace App\Models;

use App\Enums\PostStatus;
use App\Observers\TagObserver;
use Database\Factories\TagFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A topic a post can carry. One tag serves both languages, with a name in
 * each, and its English slug is the address of its page on the website.
 */
#[Fillable(['slug', 'name_en', 'name_id'])]
#[ObservedBy(TagObserver::class)]
class Tag extends Model
{
    /** @use HasFactory<TagFactory> */
    use HasFactory;

    public function posts(): BelongsToMany
    {
        return $this->belongsToMany(Post::class);
    }

    /** Whether a post that readers can see carries this tag. */
    public function isOnPublicSite(): bool
    {
        return $this->posts()->where('status', PostStatus::Published)->exists();
    }

    /**
     * The slug is part of a live address once a published post carries the
     * tag, so the admin stops editing it from then on.
     */
    public function slugIsLocked(): bool
    {
        return $this->exists && $this->isOnPublicSite();
    }
}
