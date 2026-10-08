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

        $this->actingAs(User::factory()->create());
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

    public function test_a_draft_has_no_address_to_open(): void
    {
        $draft = Post::factory()->create();

        Livewire::test(ListPosts::class)
            ->assertActionHidden(TestAction::make('view')->table($draft));
    }
}
