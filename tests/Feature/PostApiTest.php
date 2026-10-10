<?php

namespace Tests\Feature;

use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PostApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_lists_only_published_posts_newest_first(): void
    {
        $older = Post::factory()->published('2025-12-24 00:00:00')->create();
        $newer = Post::factory()->published('2026-04-07 00:00:00')->create();
        Post::factory()->create(['slug' => 'still-a-draft']);

        $response = $this->getJson('/api/posts');

        $response->assertOk()
            ->assertJsonCount(2)
            ->assertJsonPath('0.slug', $newer->slug)
            ->assertJsonPath('1.slug', $older->slug)
            ->assertJsonMissing(['slug' => 'still-a-draft']);
    }

    public function test_each_post_has_the_documented_shape(): void
    {
        $post = Post::factory()->published('2026-04-07 00:00:00')->create();

        $this->getJson('/api/posts')
            ->assertOk()
            ->assertExactJson([[
                'slug' => $post->slug,
                'published_at' => '2026-04-07T00:00:00+00:00',
                'author' => 'Damar Maulana',
                'en' => [
                    'title' => $post->title_en,
                    'description' => $post->description_en,
                    'body' => $post->body_en,
                ],
                'id' => [
                    'title' => $post->title_id,
                    'description' => $post->description_id,
                    'body' => $post->body_id,
                ],
            ]]);
    }

    public function test_the_author_is_the_writers_account_name(): void
    {
        $writer = User::factory()->create(['name' => 'Rani Putri']);
        Post::factory()->published()->create(['user_id' => $writer->id, 'author' => 'Stale Name']);

        $this->getJson('/api/posts')->assertJsonPath('0.author', 'Rani Putri');

        $writer->update(['name' => 'Rani Kusuma']);

        $this->getJson('/api/posts')->assertJsonPath('0.author', 'Rani Kusuma');
    }

    public function test_a_post_without_a_writer_keeps_its_stored_author(): void
    {
        $writer = User::factory()->create(['name' => 'Rani Putri']);
        Post::factory()->published()->create(['user_id' => $writer->id, 'author' => 'Rani Putri']);
        Post::factory()->published('2020-01-01 00:00:00')->create(['author' => 'An Imported Name']);

        $writer->delete();

        $this->getJson('/api/posts')
            ->assertJsonPath('0.author', 'Rani Putri')
            ->assertJsonPath('1.author', 'An Imported Name');
    }

    public function test_an_empty_blog_is_an_empty_array(): void
    {
        $this->getJson('/api/posts')->assertOk()->assertExactJson([]);
    }

    public function test_it_is_rate_limited(): void
    {
        $this->getJson('/api/posts')->assertHeader('X-RateLimit-Limit', '60');
    }
}
