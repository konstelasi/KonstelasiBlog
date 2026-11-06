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

    /** @var list<string> Folders made by stage(), removed after each test. */
    private array $staged = [];

    protected function tearDown(): void
    {
        foreach ($this->staged as $dir) {
            File::deleteDirectory($dir);
        }

        parent::tearDown();
    }

    /**
     * Writes paired posts into a fresh folder shaped like the importer's
     * input, `en/<slug>.md` and `id/<slug>.md`, and returns its path.
     *
     * @param  array<string, array<string, array<string, string>>>  $posts  slug => language => header fields plus `body`
     */
    private function stage(array $posts): string
    {
        $dir = storage_path('framework/testing/import-'.uniqid());
        $this->staged[] = $dir;

        foreach ($posts as $slug => $languages) {
            foreach ($languages as $lang => $post) {
                File::ensureDirectoryExists("{$dir}/{$lang}");

                $header = collect($post)->except('body')->map(fn ($value, $key) => "{$key}: {$value}")->implode("\n");
                File::put("{$dir}/{$lang}/{$slug}.md", "---\n{$header}\n---\n\n{$post['body']}\n");
            }
        }

        return $dir;
    }

    /**
     * One post in both languages. English carries any extra header fields
     * such as the date and the author, as the importer expects.
     *
     * @param  array<string, string>  $english
     * @return array<string, array<string, string>>
     */
    private function pair(string $title, string $body = 'Body text.', array $english = []): array
    {
        return [
            'en' => ['title' => $title, 'description' => "About {$title}.", ...$english, 'body' => $body],
            'id' => ['title' => "Judul {$title}", 'description' => "Tentang {$title}.", 'body' => "Isi {$body}"],
        ];
    }

    public function test_the_default_import_publishes_a_pair_with_its_date_and_author(): void
    {
        $dir = $this->stage(['hello' => $this->pair('Hello', english: ['published' => '2026-01-02', 'author' => 'A Writer'])]);

        $this->artisan('posts:import', ['path' => $dir])->assertSuccessful();

        $post = Post::where('slug', 'hello')->sole();
        $this->assertSame(PostStatus::Published, $post->status);
        $this->assertSame('2026-01-02', $post->published_at->toDateString());
        $this->assertSame('A Writer', $post->author);
        $this->assertSame('Hello', $post->title_en);
        $this->assertSame('Judul Hello', $post->title_id);
        $this->assertSame("Body text.\n", $post->body_en);
        $this->assertSame("Isi Body text.\n", $post->body_id);
    }

    public function test_the_default_import_publishes_an_existing_draft_and_replaces_its_text(): void
    {
        Post::factory()->create(['slug' => 'hello', 'title_en' => 'Old title']);
        $dir = $this->stage(['hello' => $this->pair('Hello')]);

        $this->artisan('posts:import', ['path' => $dir])->assertSuccessful();

        $post = Post::where('slug', 'hello')->sole();
        $this->assertSame(PostStatus::Published, $post->status);
        $this->assertNotNull($post->published_at);
        $this->assertSame('Hello', $post->title_en);
    }

    public function test_it_refuses_a_file_without_a_title(): void
    {
        $post = $this->pair('Hello');
        unset($post['id']['title']);
        $dir = $this->stage(['hello' => $post]);

        $this->artisan('posts:import', ['path' => $dir])
            ->expectsOutputToContain('id/hello.md has no title.')
            ->assertFailed();

        $this->assertSame(0, Post::count());
    }

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
