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
