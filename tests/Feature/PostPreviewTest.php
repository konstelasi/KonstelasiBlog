<?php

namespace Tests\Feature;

use App\Enums\PostStatus;
use App\Filament\Resources\Posts\Pages\CreatePost;
use App\Filament\Resources\Posts\Pages\EditPost;
use App\Filament\Resources\Posts\Pages\ViewPost;
use App\Models\Post;
use App\Models\Tag;
use App\Models\User;
use App\Support\PostPreview;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

class PostPreviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_markdown_is_drawn_as_the_website_draws_it(): void
    {
        $html = PostPreview::markdown("## Heading\n\n| Plan | Cost |\n| --- | --- |\n| Basic | Free |\n\n~~old~~ and \"quoted\" text...\n\n```php\necho 1;\n```");

        $this->assertStringContainsString('<h2>Heading</h2>', $html);
        $this->assertStringContainsString('<table>', $html);
        $this->assertStringContainsString('<del>old</del>', $html);
        $this->assertStringContainsString('<pre><code>echo 1;', $html);
        // Smart punctuation, as Astro does it.
        $this->assertStringContainsString('“quoted”', $html);
        $this->assertStringContainsString('…', $html);
    }

    public function test_an_empty_body_draws_nothing(): void
    {
        $this->assertSame('', PostPreview::markdown(''));
        $this->assertSame('', PostPreview::markdown("  \n "));
    }

    public function test_raw_html_is_cleaned_so_a_draft_cannot_run_a_script(): void
    {
        $html = PostPreview::markdown(<<<'MD'
        Fine text <script>alert(1)</script> and <img src="https://example.test/a.png" alt="A chart" onerror="alert(2)">

        <iframe src="https://example.test"></iframe>

        [bad](javascript:alert(3)) and [good](https://example.test/page)

        <a href="https://example.test" onclick="alert(4)">click</a>
        MD);

        // The tags a script could ride in are escaped to plain text or dropped.
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('alert(2)', $html);
        $this->assertStringNotContainsString('alert(3)', $html);
        $this->assertStringNotContainsString('alert(4)', $html);
        $this->assertStringNotContainsString('onerror', $html);
        $this->assertStringNotContainsString('onclick', $html);
        $this->assertStringNotContainsString('<iframe', $html);
        $this->assertStringNotContainsString('javascript:', $html);
        $this->assertStringContainsString('<img src="https://example.test/a.png" alt="A chart"', $html);
        $this->assertStringContainsString('href="https://example.test/page"', $html);
    }

    public function test_a_link_to_a_post_image_on_this_site_stays(): void
    {
        $html = PostPreview::markdown('![A chart](/storage/posts/chart.png)');

        $this->assertStringContainsString('src="/storage/posts/chart.png"', $html);
    }

    public function test_the_reading_time_follows_the_website_in_each_language(): void
    {
        $words = fn (int $n): string => implode(' ', array_fill(0, $n, 'word'));

        $preview = new PostPreview(['body_en' => $words(1), 'body_id' => $words(450)]);

        $this->assertSame('1 minute', $preview->readingTime('en'));
        $this->assertSame('2 menit', $preview->readingTime('id'));
        $this->assertSame('1 minute', (new PostPreview([]))->readingTime('en'));
        $this->assertSame(Post::readingMinutes($words(450)), 2);
    }

    public function test_the_date_is_printed_in_jakarta_time_and_each_language(): void
    {
        config(['app.timezone' => 'UTC']);

        // 20:00 UTC is already the next morning in Jakarta.
        $preview = new PostPreview(['status' => PostStatus::Published, 'published_at' => '2026-11-07 20:00:00']);

        $this->assertSame('8 November 2026', $preview->published('en'));
        $this->assertSame('8 November 2026', $preview->published('id'));

        $may = new PostPreview(['status' => PostStatus::Published, 'published_at' => '2026-05-02 03:00:00']);
        $this->assertSame('2 May 2026', $may->published('en'));
        $this->assertSame('2 Mei 2026', $may->published('id'));
    }

    public function test_a_draft_without_a_date_says_it_is_set_on_publishing(): void
    {
        $preview = new PostPreview(['status' => PostStatus::Draft, 'published_at' => null]);

        $this->assertSame('Set when published', $preview->published('en'));
        $this->assertSame('Diatur saat terbit', $preview->published('id'));
    }

    public function test_a_post_about_to_be_published_without_a_date_takes_today(): void
    {
        $this->travelTo('2026-11-08 10:00:00');

        $preview = new PostPreview(['status' => 'published', 'published_at' => null]);

        $this->assertSame('8 November 2026', $preview->published('en'));
    }

    public function test_the_tags_are_named_in_the_language_shown(): void
    {
        $zeta = Tag::factory()->create(['name_en' => 'Zeta', 'name_id' => 'Zeta ID']);
        $alpha = Tag::factory()->create(['name_en' => 'Alpha', 'name_id' => 'Alfa']);
        Tag::factory()->create(['name_en' => 'Unchosen', 'name_id' => 'Tak dipilih']);

        $preview = new PostPreview(['tags' => [$zeta->id, $alpha->id]]);

        $this->assertSame(['Alpha', 'Zeta'], $preview->tags('en'));
        $this->assertSame(['Alfa', 'Zeta ID'], $preview->tags('id'));
        $this->assertSame([], (new PostPreview(['tags' => []]))->tags('en'));
        $this->assertSame([], (new PostPreview([]))->tags('en'));
    }

    public function test_the_writer_is_the_posts_byline_or_else_whoever_is_signed_in(): void
    {
        $author = User::factory()->writer()->create(['name' => 'Rani Putri']);
        $viewer = User::factory()->admin()->create(['name' => 'Damar']);
        $post = Post::factory()->create(['user_id' => $author->id]);

        $this->assertSame('Rani Putri', (new PostPreview([], $post, $viewer))->writer());
        $this->assertSame('Damar', (new PostPreview([], null, $viewer))->writer());
        $this->assertSame(Post::DEFAULT_AUTHOR, (new PostPreview([]))->writer());
    }

    public function test_a_language_with_nothing_in_it_is_empty(): void
    {
        $preview = new PostPreview(['title_en' => ' Hello ', 'body_en' => '', 'title_id' => '', 'description_id' => '', 'body_id' => "\n"]);

        $this->assertFalse($preview->isEmpty('en'));
        $this->assertSame('Hello', $preview->title('en'));
        $this->assertTrue($preview->isEmpty('id'));
    }

    /**
     * The HTML of the open preview panel. Filament draws a modal as a partial
     * of the page, which `assertSee` on the page does not include, so the
     * mounted action is asked for it directly.
     */
    private function previewHtml(Testable $page): string
    {
        return $page->instance()->getMountedAction()->getModalContent()->render();
    }

    public function test_the_new_post_page_previews_what_is_typed_and_saves_nothing(): void
    {
        $this->actingAs(User::factory()->writer()->create(['name' => 'Rani Putri']));

        $page = Livewire::test(CreatePost::class)
            ->fillForm([
                'title_en' => 'A post still being written',
                'description_en' => 'One line of lead.',
                'body_en' => "Some **bold** words.\n\n- one\n- two",
                'title_id' => 'Tulisan yang masih ditulis',
            ])
            ->mountAction('preview');
        $html = $this->previewHtml($page);

        $this->assertStringContainsString('A post still being written', $html);
        $this->assertStringContainsString('One line of lead.', $html);
        $this->assertStringContainsString('<strong>bold</strong>', $html);
        $this->assertStringContainsString('Rani Putri', $html);
        $this->assertStringContainsString('Tulisan yang masih ditulis', $html);
        // Nothing is written in Indonesian beyond the title.
        $this->assertStringContainsString('No Indonesian description yet', $html);
        $this->assertStringContainsString('Nothing is written in the Indonesian body yet.', $html);
        $this->assertSame(0, Post::count());
    }

    public function test_the_edit_page_previews_unsaved_changes(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $post = Post::factory()->create(['title_en' => 'Saved title', 'body_en' => 'Saved body.']);

        $page = Livewire::test(EditPost::class, ['record' => $post->getRouteKey()])
            ->fillForm(['title_en' => 'Changed but not saved', 'body_en' => 'A [link](https://example.test).'])
            ->mountAction('preview');
        $html = $this->previewHtml($page);

        $this->assertStringContainsString('Changed but not saved', $html);
        $this->assertStringContainsString('href="https://example.test"', $html);
        $this->assertStringNotContainsString('Saved title', $html);
        $this->assertSame('Saved title', $post->fresh()->title_en);
    }

    public function test_the_preview_survives_a_draft_that_would_fail_validation(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        // Published needs every field, but a preview reads the raw state.
        $page = Livewire::test(CreatePost::class)
            ->fillForm(['status' => PostStatus::Published, 'title_en' => 'Only an English title'])
            ->mountAction('preview');

        $this->assertStringContainsString('Only an English title', $this->previewHtml($page));
    }

    public function test_the_preview_shows_the_chosen_tags_and_the_date(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $tag = Tag::factory()->create(['name_en' => 'Hosting', 'name_id' => 'Hosting ID']);

        $page = Livewire::test(CreatePost::class)
            ->fillForm(['title_en' => 'Tagged', 'tags' => [$tag->id], 'status' => PostStatus::Draft])
            ->mountAction('preview');
        $html = $this->previewHtml($page);

        $this->assertStringContainsString('Hosting', $html);
        $this->assertStringContainsString('Hosting ID', $html);
        $this->assertStringContainsString('Set when published', $html);
    }

    public function test_the_read_only_page_has_a_preview_for_someone_elses_post(): void
    {
        // A live post is made before the Writer signs in, because the
        // observer refuses a Writer who publishes.
        $theirs = Post::factory()->published()->create(['title_en' => 'A live post by someone else']);
        $this->actingAs(User::factory()->writer()->create());

        $page = Livewire::test(ViewPost::class, ['record' => $theirs->getRouteKey()])
            ->assertActionVisible('preview')
            ->mountAction('preview');

        $this->assertStringContainsString('A live post by someone else', $this->previewHtml($page));
    }

    public function test_script_in_a_draft_does_not_reach_the_preview(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $post = Post::factory()->create(['body_en' => 'Hello <script>window.stolen = 1</script> world']);

        $page = Livewire::test(ViewPost::class, ['record' => $post->getRouteKey()])->mountAction('preview');
        $html = $this->previewHtml($page);

        $this->assertStringContainsString('Hello', $html);
        $this->assertStringNotContainsString('<script>window.stolen', $html);
    }

    public function test_every_page_with_the_form_offers_the_preview(): void
    {
        $this->actingAs(User::factory()->editor()->create());
        $post = Post::factory()->create();

        Livewire::test(CreatePost::class)->assertActionExists('preview');
        Livewire::test(EditPost::class, ['record' => $post->getRouteKey()])->assertActionExists('preview');
        Livewire::test(ViewPost::class, ['record' => $post->getRouteKey()])->assertActionExists('preview');
    }
}
