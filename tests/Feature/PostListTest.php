<?php

namespace Tests\Feature;

use App\Filament\Resources\Posts\Pages\ListPosts;
use App\Models\Post;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PostListTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->admin()->create());
    }

    public function test_a_published_post_can_be_opened_on_the_site(): void
    {
        config(['services.site.url' => 'https://example.test/']);
        $live = Post::factory()->published()->create(['slug' => 'hello-stars']);

        Livewire::test(ListPosts::class)
            ->assertActionVisible(TestAction::make('view')->table($live))
            ->assertActionHasUrl(TestAction::make('view')->table($live), 'https://example.test/blog/hello-stars/')
            ->assertActionShouldOpenUrlInNewTab(TestAction::make('view')->table($live));
    }

    public function test_the_list_shows_which_languages_are_complete(): void
    {
        $half = Post::factory()->create(['body_id' => '', 'description_id' => '']);
        $full = Post::factory()->published()->create();

        Livewire::test(ListPosts::class)
            ->assertTableColumnStateSet('has_en', true, $half)
            ->assertTableColumnStateSet('has_id', false, $half)
            ->assertTableColumnStateSet('has_en', true, $full)
            ->assertTableColumnStateSet('has_id', true, $full);
    }

    public function test_a_language_needs_all_three_fields(): void
    {
        $post = Post::factory()->make();
        $this->assertTrue($post->hasLanguage('id'));

        foreach (['title_id', 'description_id', 'body_id'] as $field) {
            $partial = Post::factory()->make([$field => '  ']);
            $this->assertFalse($partial->hasLanguage('id'), "{$field} empty should fail");
            $this->assertTrue($partial->hasLanguage('en'));
        }
    }

    public function test_a_draft_has_no_address_to_open(): void
    {
        $draft = Post::factory()->create();

        Livewire::test(ListPosts::class)
            ->assertActionHidden(TestAction::make('view')->table($draft));
    }
}
