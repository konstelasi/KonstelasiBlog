<?php

namespace Tests\Feature;

use App\Enums\PostStatus;
use App\Models\Post;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class ImportPostsTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_imports_the_site_posts_as_published(): void
    {
        // The site keeps its Markdown posts only until it reads them from
        // this app's API. After that the folder is gone and this test skips.
        if (! is_dir(base_path('../src/content/blog/en'))) {
            $this->markTestSkipped('The site no longer keeps Markdown posts.');
        }

        $this->artisan('posts:import')->assertSuccessful();

        $this->assertSame(2, Post::published()->count());

        $post = Post::where('slug', 'stars-align-starcore-reaches-stability-with-v0-2-0')->sole();
        $this->assertSame(PostStatus::Published, $post->status);
        $this->assertSame('2025-12-24', $post->published_at->toDateString());
        $this->assertSame('Damar Maulana', $post->author);
        $this->assertSame('The stars align as StarCore reaches v0.2.0', $post->title_en);
        $this->assertSame('StarCore akhirnya stabil di v0.2.0', $post->title_id);
        $this->assertStringStartsWith('After a long stretch', $post->body_en);
        $this->assertStringNotContainsString('title:', $post->body_en);

        // A second run updates the same rows instead of adding new ones.
        $this->artisan('posts:import')->assertSuccessful();
        $this->assertSame(2, Post::count());
    }

    public function test_it_refuses_a_post_without_its_other_language(): void
    {
        $dir = storage_path('framework/testing/import-'.uniqid());
        File::ensureDirectoryExists("{$dir}/en");
        File::ensureDirectoryExists("{$dir}/id");
        File::put("{$dir}/en/lonely.md", "---\ntitle: Lonely\ndescription: Only English.\npublished: 2026-01-01\nauthor: Damar Maulana\n---\n\nText.\n");

        try {
            $this->artisan('posts:import', ['path' => $dir])
                ->expectsOutputToContain('en/lonely.md has no id/lonely.md')
                ->assertFailed();
        } finally {
            File::deleteDirectory($dir);
        }

        $this->assertSame(0, Post::count());
    }
}
