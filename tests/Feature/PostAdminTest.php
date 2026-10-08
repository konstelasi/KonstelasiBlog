<?php

namespace Tests\Feature;

use App\Enums\PostStatus;
use App\Filament\Resources\Posts\Pages\CreatePost;
use App\Filament\Resources\Posts\Pages\EditPost;
use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PostAdminTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
    }

    /** @return array<string, string> */
    private function complete(): array
    {
        return [
            'slug' => 'a-clean-post',
            'author' => 'Damar Maulana',
            'title_en' => 'A clean post',
            'description_en' => 'One sentence about it.',
            'body_en' => "## A heading\n\nSome text.",
            'title_id' => 'Tulisan yang rapi',
            'description_id' => 'Satu kalimat tentangnya.',
            'body_id' => "## Sebuah judul\n\nSedikit teks.",
        ];
    }

    public function test_a_complete_post_can_be_published(): void
    {
        Livewire::test(CreatePost::class)
            ->fillForm([...$this->complete(), 'status' => PostStatus::Published->value])
            ->call('create')
            ->assertHasNoFormErrors();

        $post = Post::sole();
        $this->assertSame(PostStatus::Published, $post->status);
        $this->assertNotNull($post->published_at, 'Publishing without a date takes the current time.');
    }

    public static function languageFields(): array
    {
        return array_combine(Post::languageColumns(), array_map(fn ($c) => [$c], Post::languageColumns()));
    }

    #[DataProvider('languageFields')]
    public function test_publishing_is_blocked_while_a_language_field_is_empty(string $field): void
    {
        Livewire::test(CreatePost::class)
            ->fillForm([...$this->complete(), $field => '', 'status' => PostStatus::Published->value])
            ->call('create')
            ->assertHasFormErrors([$field => 'required']);

        $this->assertSame(0, Post::count());
    }

    public function test_a_draft_may_leave_the_indonesian_side_empty(): void
    {
        Livewire::test(CreatePost::class)
            ->fillForm([
                ...$this->complete(),
                'title_id' => '', 'description_id' => '', 'body_id' => '',
                'status' => PostStatus::Draft->value,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame(PostStatus::Draft, Post::sole()->status);
    }

    public function test_the_style_rule_rejects_an_em_dash_in_a_body(): void
    {
        Livewire::test(CreatePost::class)
            ->fillForm([...$this->complete(), 'body_en' => "Fast \u{2014} and small."])
            ->call('create')
            ->assertHasFormErrors(['body_en']);
    }

    public function test_the_style_rule_rejects_a_colon_in_a_title(): void
    {
        Livewire::test(CreatePost::class)
            ->fillForm([...$this->complete(), 'title_id' => 'StarDust: langkah berikutnya'])
            ->call('create')
            ->assertHasFormErrors(['title_id']);
    }

    public function test_unpublishing_a_live_post_asks_first(): void
    {
        $live = Post::factory()->published()->create();

        Livewire::test(EditPost::class, ['record' => $live->getRouteKey()])
            ->fillForm(['status' => PostStatus::Draft->value])
            ->call('save')
            ->assertActionMounted('confirmUnpublish');

        $this->assertSame(PostStatus::Published, $live->fresh()->status, 'Nothing is saved until the writer agrees.');
    }

    public function test_the_offline_warning_names_both_addresses(): void
    {
        config(['services.site.url' => 'https://example.test/']);
        $live = Post::factory()->published()->make(['slug' => 'hello-stars']);

        $this->assertSame(
            'This post is live at https://example.test/blog/hello-stars/ and https://example.test/id/blog/hello-stars/. Both links will stop working.',
            $live->offlineWarning(),
        );
    }

    public function test_agreeing_unpublishes_the_post(): void
    {
        $live = Post::factory()->published()->create();

        Livewire::test(EditPost::class, ['record' => $live->getRouteKey()])
            ->fillForm(['status' => PostStatus::Draft->value])
            ->call('save')
            ->callMountedAction()
            ->assertHasNoFormErrors();

        $this->assertSame(PostStatus::Draft, $live->fresh()->status);
    }

    public function test_saving_a_live_post_that_stays_live_does_not_ask(): void
    {
        $live = Post::factory()->published()->create();

        Livewire::test(EditPost::class, ['record' => $live->getRouteKey()])
            ->fillForm(['title_en' => 'A corrected title'])
            ->call('save')
            ->assertActionNotMounted('confirmUnpublish');

        $this->assertSame('A corrected title', $live->fresh()->title_en);
    }

    public function test_saving_a_draft_does_not_ask(): void
    {
        $draft = Post::factory()->create();

        Livewire::test(EditPost::class, ['record' => $draft->getRouteKey()])
            ->fillForm(['title_en' => 'Still a draft'])
            ->call('save')
            ->assertActionNotMounted('confirmUnpublish');
    }

    public function test_deleting_a_live_post_names_the_links_that_will_break(): void
    {
        config(['services.site.url' => 'https://example.test']);
        $live = Post::factory()->published()->create(['slug' => 'hello-stars']);
        $draft = Post::factory()->create();

        Livewire::test(EditPost::class, ['record' => $live->getRouteKey()])
            ->assertActionExists('delete', fn ($action): bool => str_contains($action->getModalDescription(), 'https://example.test/blog/hello-stars/'));

        Livewire::test(EditPost::class, ['record' => $draft->getRouteKey()])
            ->assertActionExists('delete', fn ($action): bool => ! str_contains($action->getModalDescription(), 'stop working'));
    }

    public function test_the_form_counts_description_characters_and_body_words(): void
    {
        Livewire::test(CreatePost::class)
            ->fillForm([
                'description_en' => 'Twelve chars',
                'body_en' => 'one two three',
            ])
            ->assertSee('12 of about 160 characters')
            ->assertSee('3 words, about 1 minute to read');
    }

    public function test_the_slug_follows_the_english_title(): void
    {
        Livewire::test(CreatePost::class)
            ->fillForm(['title_en' => 'Hello from the stars'])
            ->assertSchemaStateSet(['slug' => 'hello-from-the-stars']);
    }

    public function test_the_slug_is_locked_once_the_post_is_published(): void
    {
        $draft = Post::factory()->create();
        $live = Post::factory()->published()->create();

        Livewire::test(EditPost::class, ['record' => $draft->getRouteKey()])
            ->assertFormFieldEnabled('slug');

        Livewire::test(EditPost::class, ['record' => $live->getRouteKey()])
            ->assertFormFieldDisabled('slug')
            ->fillForm(['title_en' => 'A different title'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame($live->slug, $live->fresh()->slug);
    }
}
