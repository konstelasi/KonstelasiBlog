<?php

namespace Tests\Feature;

use App\Enums\PostStatus;
use App\Filament\Resources\Posts\Pages\CreatePost;
use App\Filament\Resources\Posts\Pages\EditPost;
use App\Models\Post;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class PublishingRulesTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, string> */
    private function complete(): array
    {
        return [
            'slug' => 'a-clean-post',
            'title_en' => 'A clean post',
            'description_en' => 'One sentence about it.',
            'body_en' => "## A heading\n\nSome text.",
            'title_id' => 'Tulisan yang rapi',
            'description_id' => 'Satu kalimat tentangnya.',
            'body_id' => "## Sebuah judul\n\nSedikit teks.",
        ];
    }

    public function test_a_writer_sees_no_status_or_date_control(): void
    {
        $this->actingAs(User::factory()->writer()->create());

        Livewire::test(CreatePost::class)
            ->assertFormFieldDisabled('status')
            ->assertFormFieldDisabled('published_at');
    }

    public function test_an_editor_can_use_the_status_and_date(): void
    {
        $this->actingAs(User::factory()->editor()->create());

        Livewire::test(CreatePost::class)
            ->assertFormFieldEnabled('status')
            ->assertFormFieldEnabled('published_at');
    }

    public function test_a_writer_who_sends_published_on_create_ends_with_a_draft(): void
    {
        Bus::fake();
        $this->actingAs(User::factory()->writer()->create());

        Livewire::test(CreatePost::class)
            ->fillForm($this->complete())
            ->set('data.status', 'published')
            ->set('data.published_at', '2020-01-01 10:00:00')
            ->call('create');

        $post = Post::sole();
        $this->assertSame(PostStatus::Draft, $post->status);
        $this->assertNull($post->published_at);
        Bus::assertNothingDispatched();
    }

    public function test_a_writer_who_sends_published_on_save_keeps_a_draft(): void
    {
        $writer = User::factory()->writer()->create();
        $this->actingAs($writer);
        $post = Post::factory()->create(['user_id' => $writer->id]);

        Livewire::test(EditPost::class, ['record' => $post->getRouteKey()])
            ->fillForm(['title_en' => 'A better title'])
            ->set('data.status', 'published')
            ->set('data.published_at', '2020-01-01 10:00:00')
            ->call('save');

        $post->refresh();
        $this->assertSame('A better title', $post->title_en);
        $this->assertSame(PostStatus::Draft, $post->status);
        $this->assertNull($post->published_at);
    }

    public function test_a_writer_cannot_edit_or_delete_a_live_post_even_their_own(): void
    {
        $writer = User::factory()->writer()->create();
        $post = Post::factory()->published()->create(['user_id' => $writer->id, 'title_en' => 'Live title']);
        $this->actingAs($writer);

        Livewire::test(EditPost::class, ['record' => $post->getRouteKey()])->assertForbidden();

        $this->assertFalse($writer->can('delete', $post));
        $this->assertSame('Live title', $post->fresh()->title_en);
    }

    public function test_an_editor_publishes_a_writers_draft_and_the_byline_stays_the_writers(): void
    {
        $writer = User::factory()->writer()->create(['name' => 'Rani Putri']);
        $post = Post::factory()->create(['user_id' => $writer->id, 'author' => 'Rani Putri']);
        $this->actingAs(User::factory()->editor()->create(['name' => 'Budi Santoso']));

        Livewire::test(EditPost::class, ['record' => $post->getRouteKey()])
            ->fillForm(['status' => PostStatus::Published])
            ->call('save')
            ->assertHasNoFormErrors();

        $post->refresh();
        $this->assertSame(PostStatus::Published, $post->status);
        $this->assertNotNull($post->published_at);
        $this->assertSame($writer->id, $post->user_id);
        $this->assertSame('Rani Putri', $post->byline());
    }

    public function test_the_form_refuses_to_publish_with_a_date_still_to_come(): void
    {
        Bus::fake();
        $this->actingAs(User::factory()->editor()->create());

        Livewire::test(CreatePost::class)
            ->fillForm([
                ...$this->complete(),
                'status' => PostStatus::Published,
                'published_at' => now()->addDays(3)->format('Y-m-d H:i:s'),
            ])
            ->call('create')
            ->assertHasFormErrors(['published_at']);

        $this->assertSame(0, Post::count());
        Bus::assertNothingDispatched();
    }

    public function test_the_form_accepts_a_date_still_to_come_on_a_draft(): void
    {
        $this->actingAs(User::factory()->editor()->create());
        $at = now()->addDays(3)->startOfMinute();

        Livewire::test(CreatePost::class)
            ->fillForm([
                ...$this->complete(),
                'status' => PostStatus::Draft,
                'published_at' => $at->format('Y-m-d H:i:s'),
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $post = Post::sole();
        $this->assertSame(PostStatus::Draft, $post->status);
        $this->assertTrue($post->published_at->equalTo($at));
    }

    public function test_a_published_post_with_a_date_still_to_come_cannot_be_saved(): void
    {
        $this->expectException(ValidationException::class);

        Post::factory()->published(now()->addDay()->toDateTimeString())->create();
    }

    public function test_publishing_a_draft_that_carries_a_date_still_to_come_is_refused(): void
    {
        $post = Post::factory()->create(['published_at' => now()->addWeek()]);

        try {
            $post->update(['status' => PostStatus::Published]);
            $this->fail('The save should have been refused.');
        } catch (ValidationException $e) {
            $this->assertSame([Post::FUTURE_DATE_MESSAGE], $e->errors()['published_at']);
        }

        $this->assertSame(PostStatus::Draft, $post->fresh()->status);
    }

    public function test_a_draft_may_hold_any_date_and_a_published_post_a_past_date_or_none(): void
    {
        $planned = Post::factory()->create(['published_at' => now()->addWeek()]);
        $past = Post::factory()->published(now()->subDay()->toDateTimeString())->create();
        $none = Post::factory()->create();
        $none->update(['status' => PostStatus::Published]);

        $this->assertSame(PostStatus::Draft, $planned->fresh()->status);
        $this->assertSame(PostStatus::Published, $past->fresh()->status);
        $this->assertSame(PostStatus::Published, $none->fresh()->status);
        $this->assertNotNull($none->fresh()->published_at);
    }

    public function test_a_live_post_that_already_has_a_future_date_can_still_have_its_text_edited(): void
    {
        $post = Post::withoutEvents(fn () => Post::factory()->published(now()->addDay()->toDateTimeString())->create());
        Bus::fake();

        $post->update(['title_en' => 'A new title']);

        $this->assertSame('A new title', $post->fresh()->title_en);
    }

    public function test_the_observer_refuses_a_save_by_a_writer_that_would_publish(): void
    {
        $this->actingAs(User::factory()->writer()->create());

        $this->expectException(AuthorizationException::class);

        Post::factory()->published()->create();
    }

    public function test_the_observer_refuses_a_writer_publishing_an_existing_draft(): void
    {
        $writer = User::factory()->writer()->create();
        $post = Post::factory()->create(['user_id' => $writer->id]);
        $this->actingAs($writer);

        try {
            $post->update(['status' => PostStatus::Published]);
            $this->fail('The save should have been refused.');
        } catch (AuthorizationException) {
            $this->assertSame(PostStatus::Draft, $post->fresh()->status);
        }
    }

    public function test_the_observer_refuses_a_writer_taking_a_live_post_down(): void
    {
        $writer = User::factory()->writer()->create();
        $post = Post::factory()->published()->create(['user_id' => $writer->id]);
        $this->actingAs($writer);

        try {
            $post->update(['status' => PostStatus::Draft]);
            $this->fail('The save should have been refused.');
        } catch (AuthorizationException) {
            $this->assertSame(PostStatus::Published, $post->fresh()->status);
        }
    }

    public function test_a_writer_can_still_save_a_draft_and_an_editor_can_publish_through_the_model(): void
    {
        $writer = User::factory()->writer()->create();
        $this->actingAs($writer);
        $draft = Post::factory()->create(['user_id' => $writer->id]);
        $draft->update(['title_en' => 'Reworded']);
        $this->assertSame('Reworded', $draft->fresh()->title_en);

        $this->actingAs(User::factory()->editor()->create());
        $draft->update(['status' => PostStatus::Published]);
        $this->assertSame(PostStatus::Published, $draft->fresh()->status);
    }

    public function test_work_with_nobody_signed_in_is_not_judged(): void
    {
        $post = Post::factory()->published()->create();

        $post->update(['status' => PostStatus::Draft]);

        $this->assertSame(PostStatus::Draft, $post->fresh()->status);
    }
}
