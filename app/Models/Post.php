<?php

namespace App\Models;

use App\Enums\PostStatus;
use App\Observers\PostObserver;
use Database\Factories\PostFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'slug', 'status', 'published_at', 'author',
    'title_en', 'description_en', 'body_en',
    'title_id', 'description_id', 'body_id',
])]
#[ObservedBy(PostObserver::class)]
class Post extends Model
{
    /** @use HasFactory<PostFactory> */
    use HasFactory;

    /** The two languages every published post must have. */
    public const LANGUAGES = ['en', 'id'];

    /** The fields each language needs, as column name prefixes. */
    public const LANGUAGE_FIELDS = ['title', 'description', 'body'];

    public const DEFAULT_AUTHOR = 'Damar Maulana';

    protected $attributes = [
        'status' => 'draft',
        'author' => self::DEFAULT_AUTHOR,
    ];

    protected function casts(): array
    {
        return [
            'status' => PostStatus::class,
            'published_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // A post published without a date takes the moment it went out.
        static::saving(function (Post $post) {
            if ($post->status === PostStatus::Published && $post->published_at === null) {
                $post->published_at = now();
            }
        });
    }

    /**
     * Every language column, such as `title_en` or `body_id`.
     *
     * @return list<string>
     */
    public static function languageColumns(): array
    {
        $columns = [];
        foreach (self::LANGUAGES as $lang) {
            foreach (self::LANGUAGE_FIELDS as $field) {
                $columns[] = "{$field}_{$lang}";
            }
        }

        return $columns;
    }

    #[Scope]
    protected function published(Builder $query): void
    {
        $query->where('status', PostStatus::Published);
    }

    /**
     * The slug is part of a live URL once the post has gone out, so the
     * admin stops editing it from then on.
     */
    public function slugIsLocked(): bool
    {
        return $this->exists && $this->getOriginal('status') === PostStatus::Published;
    }
}
