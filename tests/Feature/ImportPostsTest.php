<?php

namespace Tests\Feature;

use App\Enums\PostStatus;
use App\Jobs\RebuildSite;
use App\Models\Post;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
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

    public function test_the_default_import_refuses_a_date_that_has_not_come_yet(): void
    {
        $dir = $this->stage(['later' => $this->pair('Later', english: ['published' => '"2999-01-01 00:00"'])]);

        $code = Artisan::call('posts:import', ['path' => $dir]);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('Refused later: its date has not come yet', Artisan::output());
        $this->assertSame(0, Post::count());
    }

    public function test_draft_keeps_a_date_that_has_not_come_yet_on_a_draft(): void
    {
        $dir = $this->stage(['later' => $this->pair('Later', english: ['published' => '"2999-01-01 00:00"'])]);

        Artisan::call('posts:import', ['path' => $dir, '--draft' => true]);

        $post = Post::where('slug', 'later')->sole();
        $this->assertSame(PostStatus::Draft, $post->status);
        $this->assertSame('2999-01-01 00:00:00', $post->published_at->toDateTimeString());
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

    /**
     * Runs the command and returns its exit code and printed lines.
     *
     * @param  array<string, mixed>  $options
     * @return array{0: int, 1: string}
     */
    private function importFrom(string $dir, array $options = []): array
    {
        $code = Artisan::call('posts:import', ['path' => $dir, ...$options]);

        return [$code, Artisan::output()];
    }

    public function test_draft_creates_a_draft_with_no_date_and_starts_no_rebuild(): void
    {
        Bus::fake();
        $dir = $this->stage(['hello' => $this->pair('Hello')]);

        [$code, $output] = $this->importFrom($dir, ['--draft' => true]);

        $this->assertSame(0, $code);
        $this->assertStringContainsString('Created hello (draft)', $output);

        $post = Post::where('slug', 'hello')->sole();
        $this->assertSame(PostStatus::Draft, $post->status);
        $this->assertNull($post->published_at);
        $this->assertSame(Post::DEFAULT_AUTHOR, $post->author);
        $this->assertSame('Judul Hello', $post->title_id);
        $this->assertSame([], $post->missingLanguages());
        Bus::assertNotDispatchedAfterResponse(RebuildSite::class);
    }

    public function test_draft_seeds_a_new_post_with_the_date_and_author_in_its_files(): void
    {
        $dir = $this->stage(['hello' => $this->pair('Hello', english: ['published' => '2026-01-02', 'author' => 'A Writer'])]);

        $this->importFrom($dir, ['--draft' => true]);

        $post = Post::where('slug', 'hello')->sole();
        $this->assertSame(PostStatus::Draft, $post->status);
        $this->assertSame('2026-01-02', $post->published_at->toDateString());
        $this->assertSame('A Writer', $post->author);
    }

    public function test_draft_refreshes_the_text_of_a_draft_and_leaves_the_rest_when_the_file_has_no_date(): void
    {
        Bus::fake();
        $tag = Tag::factory()->create();
        $draft = Post::factory()->create([
            'slug' => 'hello',
            'author' => 'Someone Else',
            'published_at' => '2026-02-03 10:00:00',
        ]);
        $draft->tags()->attach($tag);
        $dir = $this->stage(['hello' => $this->pair('Hello', english: ['author' => 'File Author'])]);

        [$code, $output] = $this->importFrom($dir, ['--draft' => true]);

        $this->assertSame(0, $code);
        $this->assertStringContainsString('Refreshed hello (title_en, description_en, body_en, title_id, description_id, body_id)', $output);

        $draft->refresh();
        $this->assertSame('Hello', $draft->title_en);
        $this->assertSame("Isi Body text.\n", $draft->body_id);
        $this->assertSame(PostStatus::Draft, $draft->status);
        $this->assertSame('Someone Else', $draft->author);
        $this->assertSame('2026-02-03 10:00:00', $draft->published_at->toDateTimeString());
        $this->assertSame([$tag->id], $draft->tags()->pluck('tags.id')->all());
        Bus::assertNotDispatchedAfterResponse(RebuildSite::class);

        [, $again] = $this->importFrom($dir, ['--draft' => true]);

        $this->assertStringContainsString('Unchanged hello', $again);
        $this->assertSame(1, Post::count());
    }

    public function test_draft_sets_the_date_of_a_draft_from_its_file_and_keeps_the_rest(): void
    {
        Bus::fake();
        $tag = Tag::factory()->create();
        $draft = Post::factory()->create([
            'slug' => 'hello',
            'author' => 'Someone Else',
            'published_at' => null,
        ]);
        $draft->tags()->attach($tag);
        $dir = $this->stage(['hello' => $this->pair('Hello', english: ['published' => '"2026-11-17 01:00"', 'author' => 'File Author'])]);

        [$code, $output] = $this->importFrom($dir, ['--draft' => true]);

        $this->assertSame(0, $code);
        $this->assertStringContainsString('Refreshed hello (', $output);
        $this->assertStringContainsString('published_at', $output);

        $draft->refresh();
        $this->assertSame('2026-11-17 01:00:00', $draft->published_at->toDateTimeString());
        $this->assertSame(PostStatus::Draft, $draft->status);
        $this->assertSame('Someone Else', $draft->author);
        $this->assertSame([$tag->id], $draft->tags()->pluck('tags.id')->all());
        Bus::assertNotDispatchedAfterResponse(RebuildSite::class);

        [, $again] = $this->importFrom($dir, ['--draft' => true]);

        $this->assertStringContainsString('Unchanged hello', $again);
    }

    public function test_draft_replaces_a_date_set_in_the_admin_when_the_file_has_one(): void
    {
        $draft = Post::factory()->create(['slug' => 'hello', 'published_at' => '2026-02-03 10:00:00']);
        $dir = $this->stage(['hello' => $this->pair('Hello', english: ['published' => '2026-12-01'])]);

        $this->importFrom($dir, ['--draft' => true]);

        $this->assertSame('2026-12-01 00:00:00', $draft->refresh()->published_at->toDateTimeString());
    }

    public function test_dry_run_names_the_date_it_would_set_and_writes_nothing(): void
    {
        $draft = Post::factory()->create(['slug' => 'hello', 'published_at' => null]);
        $dir = $this->stage(['hello' => $this->pair('Hello', english: ['published' => '2026-12-01'])]);

        [, $output] = $this->importFrom($dir, ['--draft' => true, '--dry-run' => true]);

        $this->assertStringContainsString('Would refresh hello (', $output);
        $this->assertStringContainsString('published_at', $output);
        $this->assertNull($draft->refresh()->published_at);
    }

    public function test_include_live_keeps_the_date_of_a_published_post_whatever_the_file_says(): void
    {
        $live = Post::factory()->published('2026-01-02 08:00:00')->create(['slug' => 'live-one']);
        $dir = $this->stage(['live-one' => $this->pair('Fixed', english: ['published' => '2030-01-01'])]);
        Bus::fake();

        $this->importFrom($dir, ['--draft' => true, '--include-live' => true]);

        $live->refresh();
        $this->assertSame('Fixed', $live->title_en);
        $this->assertSame('2026-01-02 08:00:00', $live->published_at->toDateTimeString());
    }

    public function test_writer_links_a_new_draft_to_the_account_and_ignores_the_author_line(): void
    {
        $user = User::factory()->create(['name' => 'Damar', 'email' => 'damar@example.test']);
        $dir = $this->stage(['hello' => $this->pair('Hello', english: ['author' => 'File Author'])]);

        [$code] = $this->importFrom($dir, ['--draft' => true, '--writer' => 'damar@example.test']);

        $this->assertSame(0, $code);
        $post = Post::where('slug', 'hello')->sole();
        $this->assertSame($user->id, $post->user_id);
        $this->assertSame('Damar', $post->author);
        $this->assertSame('Damar', $post->byline());
        $this->assertSame(PostStatus::Draft, $post->status);
    }

    public function test_writer_links_a_draft_that_has_no_writer(): void
    {
        $user = User::factory()->create(['name' => 'Damar', 'email' => 'damar@example.test']);
        $draft = Post::factory()->create(['slug' => 'hello', 'author' => 'Damar Maulana', 'user_id' => null]);
        $dir = $this->stage(['hello' => $this->pair('Hello')]);

        [, $output] = $this->importFrom($dir, ['--draft' => true, '--writer' => 'damar@example.test']);

        $this->assertMatchesRegularExpression('/Refreshed hello \([^)]*\buser_id\b[^)]*\)/', $output);
        $this->assertMatchesRegularExpression('/Refreshed hello \([^)]*\bauthor\b[^)]*\)/', $output);
        $draft->refresh();
        $this->assertSame($user->id, $draft->user_id);
        $this->assertSame('Damar', $draft->author);
        $this->assertSame('Damar', $draft->byline());

        [, $again] = $this->importFrom($dir, ['--draft' => true, '--writer' => 'damar@example.test']);

        $this->assertStringContainsString('Unchanged hello', $again);
    }

    public function test_writer_keeps_the_writer_a_draft_already_has(): void
    {
        $owner = User::factory()->create(['name' => 'Owner']);
        User::factory()->create(['email' => 'damar@example.test']);
        $draft = Post::factory()->create(['slug' => 'hello', 'author' => 'Owner', 'user_id' => $owner->id]);
        $dir = $this->stage(['hello' => $this->pair('Hello')]);

        $this->importFrom($dir, ['--draft' => true, '--writer' => 'damar@example.test']);

        $draft->refresh();
        $this->assertSame($owner->id, $draft->user_id);
        $this->assertSame('Owner', $draft->author);
        $this->assertSame('Hello', $draft->title_en);
    }

    public function test_writer_leaves_a_published_post_alone_with_include_live(): void
    {
        User::factory()->create(['name' => 'Damar', 'email' => 'damar@example.test']);
        $live = Post::factory()->published('2026-01-02 08:00:00')->create(['slug' => 'live-one', 'author' => 'Old Name', 'user_id' => null]);
        $dir = $this->stage(['live-one' => $this->pair('Fixed')]);
        Bus::fake();

        $this->importFrom($dir, ['--draft' => true, '--include-live' => true, '--writer' => 'damar@example.test']);

        $live->refresh();
        $this->assertSame('Fixed', $live->title_en);
        $this->assertNull($live->user_id);
        $this->assertSame('Old Name', $live->author);
    }

    public function test_an_unknown_writer_email_refuses_the_whole_run_and_writes_nothing(): void
    {
        $dir = $this->stage(['hello' => $this->pair('Hello')]);

        [$code, $output] = $this->importFrom($dir, ['--draft' => true, '--writer' => 'nobody@example.test']);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('No account has the email nobody@example.test', $output);
        $this->assertFalse(Post::where('slug', 'hello')->exists());
    }

    public function test_dry_run_names_the_writer_fields_it_would_set_and_writes_nothing(): void
    {
        User::factory()->create(['email' => 'damar@example.test']);
        $draft = Post::factory()->create(['slug' => 'hello', 'author' => 'Damar Maulana', 'user_id' => null]);
        $dir = $this->stage(['hello' => $this->pair('Hello'), 'fresh' => $this->pair('Fresh')]);

        [, $output] = $this->importFrom($dir, ['--draft' => true, '--dry-run' => true, '--writer' => 'damar@example.test']);

        $this->assertMatchesRegularExpression('/Would refresh hello \([^)]*\buser_id\b[^)]*\)/', $output);
        $this->assertMatchesRegularExpression('/Would refresh hello \([^)]*\bauthor\b[^)]*\)/', $output);
        $this->assertStringContainsString('Would create fresh (draft)', $output);
        $this->assertNull($draft->refresh()->user_id);
        $this->assertFalse(Post::where('slug', 'fresh')->exists());
    }

    public function test_writer_needs_draft(): void
    {
        $dir = $this->stage(['hello' => $this->pair('Hello')]);

        [$code, $output] = $this->importFrom($dir, ['--writer' => 'damar@example.test']);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('--writer only works together with --draft.', $output);
        $this->assertSame(0, Post::count());
    }

    /**
     * The slugs of a post's tags, sorted, so a test can compare the set.
     *
     * @return list<string>
     */
    private function tagSlugsOf(Post $post): array
    {
        return $post->tags()->orderBy('slug')->pluck('slug')->all();
    }

    public function test_tags_line_gives_a_new_draft_its_tags_and_starts_no_rebuild(): void
    {
        Tag::factory()->create(['slug' => 'mysql']);
        Tag::factory()->create(['slug' => 'stardust']);
        Bus::fake();
        $dir = $this->stage(['hello' => $this->pair('Hello', english: ['tags' => '[mysql, stardust]'])]);

        [$code, $output] = $this->importFrom($dir, ['--draft' => true]);

        $this->assertSame(0, $code);
        $this->assertStringContainsString('Created hello (draft)', $output);
        $this->assertSame(['mysql', 'stardust'], $this->tagSlugsOf(Post::where('slug', 'hello')->sole()));
        Bus::assertNotDispatchedAfterResponse(RebuildSite::class);
    }

    public function test_tags_line_sets_a_draft_to_exactly_the_listed_tags(): void
    {
        $old = Tag::factory()->create(['slug' => 'old']);
        Tag::factory()->create(['slug' => 'mysql']);
        $draft = Post::factory()->create(['slug' => 'hello']);
        $draft->tags()->attach($old);
        $dir = $this->stage(['hello' => $this->pair('Hello', english: ['tags' => '[mysql]'])]);

        [, $output] = $this->importFrom($dir, ['--draft' => true]);

        $this->assertMatchesRegularExpression('/Refreshed hello \([^)]*\btags\b[^)]*\)/', $output);
        $this->assertSame(['mysql'], $this->tagSlugsOf($draft));
    }

    public function test_an_empty_tags_list_clears_the_tags_of_a_draft(): void
    {
        $draft = Post::factory()->create(['slug' => 'hello']);
        $draft->tags()->attach(Tag::factory()->create(['slug' => 'old']));
        $dir = $this->stage(['hello' => $this->pair('Hello', english: ['tags' => '[]'])]);

        [, $output] = $this->importFrom($dir, ['--draft' => true]);

        $this->assertMatchesRegularExpression('/Refreshed hello \([^)]*\btags\b[^)]*\)/', $output);
        $this->assertSame([], $this->tagSlugsOf($draft));
    }

    public function test_a_file_with_no_tags_line_leaves_the_tags_of_a_draft_alone(): void
    {
        $draft = Post::factory()->create(['slug' => 'hello']);
        $draft->tags()->attach(Tag::factory()->create(['slug' => 'old']));
        $dir = $this->stage(['hello' => $this->pair('Hello')]);

        [, $output] = $this->importFrom($dir, ['--draft' => true]);

        $this->assertDoesNotMatchRegularExpression('/\btags\b/', $output);
        $this->assertSame(['old'], $this->tagSlugsOf($draft));
    }

    public function test_the_same_tags_in_another_order_are_unchanged(): void
    {
        Tag::factory()->create(['slug' => 'mysql']);
        Tag::factory()->create(['slug' => 'stardust']);
        $this->importFrom($this->stage(['hello' => $this->pair('Hello', english: ['tags' => '[mysql, stardust]'])]), ['--draft' => true]);

        [, $output] = $this->importFrom(
            $this->stage(['hello' => $this->pair('Hello', english: ['tags' => '[stardust, mysql, mysql]'])]),
            ['--draft' => true],
        );

        $this->assertStringContainsString('Unchanged hello', $output);
    }

    public function test_the_indonesian_file_supplies_the_tags_when_english_has_no_line(): void
    {
        Tag::factory()->create(['slug' => 'mysql']);
        $posts = $this->pair('Hello');
        $posts['id']['tags'] = '[mysql]';
        $dir = $this->stage(['hello' => $posts]);

        $this->importFrom($dir, ['--draft' => true]);

        $this->assertSame(['mysql'], $this->tagSlugsOf(Post::where('slug', 'hello')->sole()));
    }

    public function test_an_unknown_tag_slug_refuses_the_whole_run_and_writes_nothing(): void
    {
        Tag::factory()->create(['slug' => 'mysql']);
        $draft = Post::factory()->create(['slug' => 'kept', 'title_en' => 'Kept title']);
        $dir = $this->stage([
            'kept' => $this->pair('Changed'),
            'hello' => $this->pair('Hello', english: ['tags' => '[mysql, nope]']),
        ]);

        [$code, $output] = $this->importFrom($dir, ['--draft' => true]);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('No tag has the slug nope.', $output);
        $this->assertStringNotContainsString('slug mysql', $output);
        $this->assertFalse(Post::where('slug', 'hello')->exists());
        $this->assertSame('Kept title', $draft->refresh()->title_en);
    }

    public function test_more_tags_than_a_post_takes_refuses_the_run(): void
    {
        $dir = $this->stage(['hello' => $this->pair('Hello', english: ['tags' => '[a, b, c, d, e, f, g]'])]);

        [$code, $output] = $this->importFrom($dir, ['--draft' => true]);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('en/hello.md lists 7 tags, and a post takes at most 6.', $output);
        $this->assertSame(0, Post::count());
    }

    public function test_a_tags_line_that_is_not_a_list_of_slugs_refuses_the_run(): void
    {
        foreach (['mysql', '[2026]', '{a: b}'] as $line) {
            $dir = $this->stage(['hello' => $this->pair('Hello', english: ['tags' => $line])]);

            [$code, $output] = $this->importFrom($dir, ['--draft' => true]);

            $this->assertSame(1, $code, $line);
            $this->assertStringContainsString('en/hello.md has a tags line', $output, $line);
            $this->assertSame(0, Post::count(), $line);
        }
    }

    public function test_include_live_leaves_the_tags_of_a_published_post_alone(): void
    {
        Tag::factory()->create(['slug' => 'mysql']);
        $live = Post::factory()->published('2026-01-02 08:00:00')->create(['slug' => 'live-one']);
        $live->tags()->attach(Tag::factory()->create(['slug' => 'old']));
        $dir = $this->stage(['live-one' => $this->pair('Fixed', english: ['tags' => '[mysql]'])]);
        Bus::fake();

        $this->importFrom($dir, ['--draft' => true, '--include-live' => true]);

        $this->assertSame('Fixed', $live->refresh()->title_en);
        $this->assertSame(['old'], $this->tagSlugsOf($live));
    }

    public function test_dry_run_names_the_tags_it_would_set_and_writes_nothing(): void
    {
        Tag::factory()->create(['slug' => 'mysql']);
        $draft = Post::factory()->create(['slug' => 'hello']);
        $draft->tags()->attach(Tag::factory()->create(['slug' => 'old']));
        $dir = $this->stage([
            'hello' => $this->pair('Hello', english: ['tags' => '[mysql]']),
            'fresh' => $this->pair('Fresh', english: ['tags' => '[mysql]']),
        ]);

        [, $output] = $this->importFrom($dir, ['--draft' => true, '--dry-run' => true]);

        $this->assertMatchesRegularExpression('/Would refresh hello \([^)]*\btags\b[^)]*\)/', $output);
        $this->assertStringContainsString('Would create fresh (draft)', $output);
        $this->assertSame(['old'], $this->tagSlugsOf($draft));
        $this->assertFalse(Post::where('slug', 'fresh')->exists());
    }

    public function test_the_default_import_does_not_read_tags(): void
    {
        $dir = $this->stage(['hello' => $this->pair('Hello', english: ['tags' => 'mysql'])]);

        $this->artisan('posts:import', ['path' => $dir])->assertSuccessful();

        $this->assertSame([], $this->tagSlugsOf(Post::where('slug', 'hello')->sole()));
    }

    public function test_draft_refuses_the_whole_run_when_a_slug_is_published(): void
    {
        $live = Post::factory()->published()->create(['slug' => 'live-one', 'title_en' => 'Live title']);
        $dir = $this->stage(['live-one' => $this->pair('Changed'), 'new-one' => $this->pair('New')]);

        [$code, $output] = $this->importFrom($dir, ['--draft' => true]);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('Refused live-one', $output);
        $this->assertSame('Live title', $live->fresh()->title_en);
        $this->assertFalse(Post::where('slug', 'new-one')->exists());
    }

    public function test_include_live_refreshes_a_published_post_and_starts_one_rebuild(): void
    {
        $live = Post::factory()->published('2026-01-02 08:00:00')->create(['slug' => 'live-one']);
        $dir = $this->stage(['live-one' => $this->pair('Fixed')]);
        Bus::fake();

        [$code, $output] = $this->importFrom($dir, ['--draft' => true, '--include-live' => true]);

        $this->assertSame(0, $code);
        $this->assertStringContainsString('Refreshed live post live-one (', $output);

        $live->refresh();
        $this->assertSame('Fixed', $live->title_en);
        $this->assertSame(PostStatus::Published, $live->status);
        $this->assertSame('2026-01-02 08:00:00', $live->published_at->toDateTimeString());
        Bus::assertDispatchedAfterResponse(RebuildSite::class, 1);

        Bus::fake();

        [, $again] = $this->importFrom($dir, ['--draft' => true, '--include-live' => true]);

        $this->assertStringContainsString('Unchanged live-one', $again);
        Bus::assertNotDispatchedAfterResponse(RebuildSite::class);
    }

    public function test_include_live_still_saves_new_posts_and_existing_drafts_as_drafts(): void
    {
        Post::factory()->published()->create(['slug' => 'live-one']);
        Post::factory()->create(['slug' => 'draft-one']);
        $dir = $this->stage([
            'live-one' => $this->pair('Live'),
            'draft-one' => $this->pair('Draft'),
            'new-one' => $this->pair('New'),
        ]);
        Bus::fake();

        $this->importFrom($dir, ['--draft' => true, '--include-live' => true]);

        $this->assertSame(PostStatus::Published, Post::where('slug', 'live-one')->sole()->status);
        $this->assertSame(PostStatus::Draft, Post::where('slug', 'draft-one')->sole()->status);
        $this->assertSame(PostStatus::Draft, Post::where('slug', 'new-one')->sole()->status);
        Bus::assertDispatchedAfterResponse(RebuildSite::class, 1);
    }

    public function test_include_live_needs_draft(): void
    {
        $dir = $this->stage(['hello' => $this->pair('Hello')]);

        [$code, $output] = $this->importFrom($dir, ['--include-live' => true]);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('--include-live only works together with --draft.', $output);
        $this->assertSame(0, Post::count());
    }

    public function test_dry_run_writes_nothing_and_says_what_would_happen(): void
    {
        Post::factory()->create(['slug' => 'draft-one', 'title_en' => 'Old draft']);
        $live = Post::factory()->published()->create(['slug' => 'live-one', 'title_en' => 'Old live']);
        $dir = $this->stage([
            'new-one' => $this->pair('New'),
            'draft-one' => $this->pair('Draft'),
            'live-one' => $this->pair('Live'),
        ]);
        Bus::fake();

        [$code, $output] = $this->importFrom($dir, ['--draft' => true, '--include-live' => true, '--dry-run' => true]);

        $this->assertSame(0, $code);
        $this->assertStringContainsString('Would create new-one (draft)', $output);
        $this->assertStringContainsString('Would refresh draft-one (', $output);
        $this->assertStringContainsString('Would refresh live post live-one (', $output);
        $this->assertStringContainsString('Dry run, nothing was written.', $output);

        $this->assertFalse(Post::where('slug', 'new-one')->exists());
        $this->assertSame('Old draft', Post::where('slug', 'draft-one')->sole()->title_en);
        $this->assertSame('Old live', $live->fresh()->title_en);
        Bus::assertNotDispatchedAfterResponse(RebuildSite::class);
    }

    public function test_dry_run_fails_on_the_same_refusal_as_a_real_run(): void
    {
        Post::factory()->published()->create(['slug' => 'live-one']);
        $dir = $this->stage(['live-one' => $this->pair('Live'), 'new-one' => $this->pair('New')]);

        [$code, $output] = $this->importFrom($dir, ['--draft' => true, '--dry-run' => true]);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('Refused live-one', $output);
        $this->assertFalse(Post::where('slug', 'new-one')->exists());
    }

    public function test_dry_run_needs_draft(): void
    {
        $dir = $this->stage(['hello' => $this->pair('Hello')]);

        [$code, $output] = $this->importFrom($dir, ['--dry-run' => true]);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('--dry-run only works together with --draft.', $output);
        $this->assertSame(0, Post::count());
    }

    public function test_draft_reads_every_file_before_writing_any(): void
    {
        $broken = $this->pair('Broken');
        unset($broken['id']['title']);
        $dir = $this->stage(['broken' => $broken, 'fine' => $this->pair('Fine')]);

        [$code, $output] = $this->importFrom($dir, ['--draft' => true]);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('id/broken.md has no title.', $output);
        $this->assertSame(0, Post::count());
    }

    public function test_draft_warns_about_a_style_problem_and_keeps_the_text(): void
    {
        $dir = $this->stage(['hello' => $this->pair('Hello', "One \u{2014} two.")]);

        [$code, $output] = $this->importFrom($dir, ['--draft' => true]);

        $this->assertSame(0, $code);
        $this->assertStringContainsString('hello body_en', $output);
        $this->assertStringContainsString("\u{2014}", Post::where('slug', 'hello')->sole()->body_en);
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
