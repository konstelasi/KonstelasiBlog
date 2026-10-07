<?php

namespace Database\Factories;

use App\Enums\PostStatus;
use App\Models\Post;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Post>
 */
class PostFactory extends Factory
{
    public function definition(): array
    {
        $title = rtrim(fake()->unique()->sentence(5), '.');

        return [
            'slug' => Str::slug($title),
            'status' => PostStatus::Draft,
            'published_at' => null,
            'author' => Post::DEFAULT_AUTHOR,
            'title_en' => $title,
            'description_en' => fake()->sentence(12),
            'body_en' => "## A heading\n\n".fake()->paragraph(),
            'title_id' => 'Judul '.$title,
            'description_id' => fake()->sentence(12),
            'body_id' => "## Sebuah judul\n\n".fake()->paragraph(),
        ];
    }

    public function published(?string $at = null): static
    {
        return $this->state(fn () => [
            'status' => PostStatus::Published,
            'published_at' => $at ?? now()->subDay(),
        ]);
    }
}
