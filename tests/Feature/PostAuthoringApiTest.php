<?php

namespace Tests\Feature;

use App\Enums\PostStatus;
use App\Jobs\RebuildSite;
use App\Models\Post;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PostAuthoringApiTest extends TestCase
{
    use RefreshDatabase;

    private const WRITE = ['posts:write'];

    private const PUBLISH = ['posts:write', 'posts:publish'];

    /**
     * A complete post in both languages that passes the house style.
     *
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function body(array $extra = []): array
    {
        return [
            'en' => ['title' => 'A clean post', 'description' => 'One sentence about it.', 'body' => "## A heading\n\nSome text.\n"],
            'id' => ['title' => 'Tulisan yang rapi', 'description' => 'Satu kalimat tentangnya.', 'body' => "## Sebuah judul\n\nSedikit teks.\n"],
            ...$extra,
        ];
    }

    private function editor(array $abilities = self::WRITE): User
    {
        $user = User::factory()->editor()->create(['name' => 'Budi Santoso']);
        Sanctum::actingAs($user, $abilities);

        return $user;
    }

    private function writer(array $abilities = self::PUBLISH): User
    {
        $user = User::factory()->writer()->create(['name' => 'Rani Putri']);
        Sanctum::actingAs($user, $abilities);

        return $user;
    }

    public function test_a_request_without_a_token_is_refused(): void
    {
        $this->putJson('/api/v1/posts/a-clean-post', $this->body())->assertUnauthorized();
        $this->getJson('/api/v1/posts/a-clean-post')->assertUnauthorized();
        $this->assertSame(0, Post::count());
    }

    public function test_a_session_cookie_does_not_stand_in_for_a_token(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        $this->putJson('/api/v1/posts/a-clean-post', $this->body())->assertUnauthorized();
    }

    public function test_a_token_without_the_write_ability_is_refused(): void
    {
        $this->editor(['something:else']);

        $this->putJson('/api/v1/posts/a-clean-post', $this->body())->assertForbidden();
        $this->assertSame(0, Post::count());
    }

    public function test_a_real_token_works_until_it_expires_or_is_revoked(): void
    {
        $user = User::factory()->editor()->create();
        $live = $user->createToken('push', self::WRITE, now()->addDay());
        $expired = $user->createToken('old', self::WRITE, now()->subMinute());

        $this->withToken($live->plainTextToken)->putJson('/api/v1/posts/a-clean-post', $this->body())->assertCreated();
        $this->app['auth']->forgetGuards();
        $this->withToken($expired->plainTextToken)->putJson('/api/v1/posts/another-post', $this->body())->assertUnauthorized();

        $live->accessToken->delete();
        $this->app['auth']->forgetGuards();
        $this->withToken($live->plainTextToken)->putJson('/api/v1/posts/third-post', $this->body())->assertUnauthorized();

        $this->assertSame(['a-clean-post'], Post::pluck('slug')->all());
    }

    public function test_it_creates_a_draft_for_the_account_behind_the_token(): void
    {
        Bus::fake();
        $user = $this->editor();

        $response = $this->putJson('/api/v1/posts/a-clean-post', $this->body());

        $response->assertCreated()
            ->assertJsonPath('meta.action', 'created')
            ->assertJsonPath('meta.dry_run', false)
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.writer.id', $user->id)
            ->assertJsonPath('data.author', 'Budi Santoso')
            ->assertJsonPath('data.en.title', 'A clean post')
            ->assertJsonPath('data.urls', null);

        $post = Post::sole();
        $this->assertSame($user->id, $post->user_id);
        $this->assertSame('Budi Santoso', $post->author);
        $this->assertSame("## A heading\n\nSome text.\n", $post->body_en);
        Bus::assertNotDispatchedAfterResponse(RebuildSite::class);
    }

    public function test_it_stores_the_text_exactly_as_sent(): void
    {
        $this->editor();

        $this->putJson('/api/v1/posts/a-clean-post', $this->body(['en' => ['title' => ' Spaced ', 'description' => '', 'body' => "Text\n\n"]]))->assertCreated();

        $post = Post::sole();
        $this->assertSame(' Spaced ', $post->title_en);
        $this->assertSame('', $post->description_en);
        $this->assertSame("Text\n\n", $post->body_en);
    }

    public function test_a_draft_may_carry_a_date_still_to_come_and_it_is_stored_in_utc(): void
    {
        $this->editor();

        $response = $this->putJson('/api/v1/posts/a-clean-post', $this->body(['published_at' => '2999-11-17T08:00:00+07:00']));

        $response->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.published_at', '2999-11-17T01:00:00Z');
        $this->assertSame('2999-11-17 01:00:00', Post::sole()->published_at->toDateTimeString());
    }

    public function test_a_date_without_a_timezone_is_refused(): void
    {
        $this->editor();

        foreach (['2026-11-17 01:00', '2026-11-17', '2026-11-17T01:00', '2026-13-45T01:00:00Z'] as $date) {
            $this->putJson('/api/v1/posts/a-clean-post', $this->body(['published_at' => $date]))
                ->assertUnprocessable()
                ->assertJsonValidationErrors('published_at');
        }
        $this->assertSame(0, Post::count());
    }

    public function test_publishing_with_a_date_still_to_come_is_refused_with_a_stable_code(): void
    {
        Bus::fake();
        $this->editor(self::PUBLISH);

        $this->putJson('/api/v1/posts/a-clean-post', $this->body(['status' => 'published', 'published_at' => '2999-01-01T00:00:00Z']))
            ->assertUnprocessable()
            ->assertJsonPath('code', 'future_date_requires_draft')
            ->assertJsonPath('errors.published_at.0', Post::FUTURE_DATE_MESSAGE);

        $this->assertSame(0, Post::count());
        Bus::assertNothingDispatched();
    }

    public function test_publishing_a_draft_that_holds_a_date_still_to_come_is_refused(): void
    {
        $this->editor(self::PUBLISH);
        Post::factory()->create(['slug' => 'planned', 'published_at' => now()->addWeek()]);

        $this->putJson('/api/v1/posts/planned', ['status' => 'published'])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'future_date_requires_draft');

        $this->assertSame(PostStatus::Draft, Post::sole()->status);
    }

    public function test_a_write_only_token_cannot_publish(): void
    {
        $this->editor(self::WRITE);

        $this->putJson('/api/v1/posts/a-clean-post', $this->body(['status' => 'published']))
            ->assertForbidden()
            ->assertJsonPath('code', 'ability_missing');

        $this->assertSame(0, Post::count());
    }

    public function test_a_publishing_token_publishes_and_the_site_is_rebuilt(): void
    {
        Bus::fake();
        config(['services.github.token' => 'a-token']);
        $this->editor(self::PUBLISH);

        $response = $this->putJson('/api/v1/posts/a-clean-post', $this->body(['status' => 'published', 'published_at' => '2026-01-02T01:00:00Z']));

        $response->assertCreated()
            ->assertJsonPath('data.status', 'published')
            ->assertJsonPath('data.urls.en', 'https://konstelasi.co.id/blog/a-clean-post/')
            ->assertJsonPath('data.urls.id', 'https://konstelasi.co.id/id/blog/a-clean-post/')
            ->assertJsonPath('meta.site_rebuild', true);
        $this->assertSame(PostStatus::Published, Post::sole()->status);
        Bus::assertDispatchedAfterResponse(RebuildSite::class);
    }

    public function test_a_published_post_without_a_date_takes_the_moment_it_goes_out(): void
    {
        $this->editor(self::PUBLISH);

        $this->putJson('/api/v1/posts/a-clean-post', $this->body(['status' => 'published']))->assertCreated();

        $this->assertNotNull(Post::sole()->published_at);
    }

    public function test_a_post_that_is_not_complete_cannot_be_published(): void
    {
        $this->editor(self::PUBLISH);
        $incomplete = $this->body(['status' => 'published']);
        $incomplete['id']['body'] = '';

        $response = $this->putJson('/api/v1/posts/a-clean-post', $incomplete);

        $response->assertUnprocessable()->assertJsonPath('code', 'language_incomplete');
        $this->assertStringContainsString('Missing in Indonesian.', $response->json('message'));
        $this->assertSame(0, Post::count());
    }

    public function test_text_that_breaks_the_house_style_blocks_a_publish_and_only_warns_on_a_draft(): void
    {
        $this->editor(self::PUBLISH);
        $dash = $this->body();
        $dash['en']['body'] = "Fast \u{2014} and small.\n";

        $this->putJson('/api/v1/posts/a-clean-post', [...$dash, 'status' => 'published'])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'house_style')
            ->assertJsonStructure(['errors' => ['en.body']]);
        $this->assertSame(0, Post::count());

        $draft = $this->putJson('/api/v1/posts/a-clean-post', $dash);

        $draft->assertCreated();
        $this->assertCount(1, $draft->json('meta.warnings'));
        $this->assertStringStartsWith('en.body: ', $draft->json('meta.warnings.0'));
        $this->assertSame(1, Post::count());
    }

    public function test_a_writer_keeps_drafts_and_cannot_publish_or_set_a_date(): void
    {
        $writer = $this->writer();

        $this->putJson('/api/v1/posts/mine', $this->body())->assertCreated()->assertJsonPath('data.writer.id', $writer->id);
        $this->putJson('/api/v1/posts/mine', $this->body(['status' => 'published']))
            ->assertForbidden()->assertJsonPath('code', 'not_allowed');
        $this->putJson('/api/v1/posts/mine', ['published_at' => '2026-01-02T01:00:00Z'])
            ->assertForbidden()->assertJsonPath('code', 'not_allowed');

        $this->assertSame(PostStatus::Draft, Post::sole()->status);
        $this->assertNull(Post::sole()->published_at);
    }

    public function test_a_writer_cannot_touch_someone_elses_draft_or_a_live_post(): void
    {
        // Live fixtures first. Once the writer is signed in, the observer
        // refuses to save a post that goes live.
        Post::factory()->create(['slug' => 'theirs', 'user_id' => User::factory()->writer()->create()->id, 'title_en' => 'Theirs']);
        Post::factory()->published()->create(['slug' => 'live', 'title_en' => 'Live']);
        $this->writer();

        $this->putJson('/api/v1/posts/theirs', ['en' => ['title' => 'Mine now']])->assertForbidden()->assertJsonPath('code', 'not_allowed');
        $this->putJson('/api/v1/posts/live', ['en' => ['title' => 'Mine now']])->assertForbidden();

        $this->assertSame(['Theirs', 'Live'], Post::orderBy('id')->pluck('title_en')->all());
    }

    public function test_it_updates_only_what_is_sent(): void
    {
        $this->editor();
        $this->putJson('/api/v1/posts/a-clean-post', $this->body())->assertCreated();

        $response = $this->putJson('/api/v1/posts/a-clean-post', ['en' => ['title' => 'A better title']]);

        $response->assertOk()
            ->assertJsonPath('meta.action', 'updated')
            ->assertJsonPath('meta.changes', ['title_en'])
            ->assertJsonPath('data.en.title', 'A better title')
            ->assertJsonPath('data.en.body', "## A heading\n\nSome text.\n");
        $this->assertSame('Tulisan yang rapi', Post::sole()->title_id);
    }

    public function test_the_same_request_twice_changes_nothing_the_second_time(): void
    {
        Bus::fake();
        $this->editor();
        $body = $this->body(['published_at' => '2999-01-01T00:00:00Z']);
        $this->putJson('/api/v1/posts/a-clean-post', $body)->assertCreated();
        $before = Post::sole()->updated_at;

        $this->travel(5)->minutes();
        $again = $this->putJson('/api/v1/posts/a-clean-post', $body);

        $again->assertOk()->assertJsonPath('meta.action', 'unchanged')->assertJsonPath('meta.changes', []);
        $this->assertTrue(Post::sole()->updated_at->equalTo($before));
    }

    public function test_the_writer_of_an_existing_post_does_not_change(): void
    {
        $owner = User::factory()->writer()->create(['name' => 'Rani Putri']);
        Post::factory()->create(['slug' => 'hers', 'user_id' => $owner->id, 'author' => 'Rani Putri']);
        $this->editor();

        $this->putJson('/api/v1/posts/hers', ['en' => ['title' => 'Edited by an editor']])->assertOk();

        $this->assertSame($owner->id, Post::sole()->user_id);
        $this->assertSame('Rani Putri', Post::sole()->byline());
    }

    public function test_a_write_only_token_cannot_edit_a_live_post_but_a_publishing_token_can(): void
    {
        Bus::fake();
        $this->editor(self::WRITE);
        Post::factory()->published('2026-01-02 08:00:00')->create(['slug' => 'live', 'title_en' => 'Live title']);

        $this->putJson('/api/v1/posts/live', ['en' => ['title' => 'Changed']])->assertForbidden()->assertJsonPath('code', 'ability_missing');
        $this->assertSame('Live title', Post::sole()->title_en);

        $this->editor(self::PUBLISH);
        $this->putJson('/api/v1/posts/live', ['en' => ['title' => 'Changed']])->assertOk();

        $this->assertSame('Changed', Post::sole()->title_en);
        Bus::assertDispatchedAfterResponse(RebuildSite::class);
    }

    public function test_a_dry_run_checks_everything_and_writes_nothing(): void
    {
        Bus::fake();
        $this->editor(self::PUBLISH);

        $created = $this->putJson('/api/v1/posts/a-clean-post?dry_run=1', $this->body());

        $created->assertOk()->assertJsonPath('meta.dry_run', true)->assertJsonPath('meta.action', 'created');
        $this->assertSame(0, Post::count());

        Post::factory()->create(['slug' => 'existing', 'title_en' => 'Old title']);
        $updated = $this->putJson('/api/v1/posts/existing?dry_run=1', ['en' => ['title' => 'New title']]);

        $updated->assertOk()->assertJsonPath('meta.action', 'updated')->assertJsonPath('data.en.title', 'New title');
        $this->assertSame('Old title', Post::sole()->title_en);

        $this->putJson('/api/v1/posts/existing?dry_run=1', ['status' => 'published', 'published_at' => '2999-01-01T00:00:00Z'])
            ->assertUnprocessable()->assertJsonPath('code', 'future_date_requires_draft');
        Bus::assertNothingDispatched();
    }

    public function test_tags_are_set_by_slug_and_an_unknown_slug_refuses_the_write(): void
    {
        $this->editor();
        Tag::factory()->create(['slug' => 'mysql']);
        Tag::factory()->create(['slug' => 'stardust']);

        $this->putJson('/api/v1/posts/a-clean-post', $this->body(['tags' => ['stardust', 'mysql', 'mysql']]))
            ->assertCreated()
            ->assertJsonPath('data.tags', ['mysql', 'stardust']);
        $this->assertSame(['mysql', 'stardust'], Post::sole()->tags()->orderBy('slug')->pluck('slug')->all());

        $this->putJson('/api/v1/posts/a-clean-post', ['en' => ['title' => 'Changed'], 'tags' => ['mysql', 'nope']])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'unknown_tag');
        $this->assertSame('A clean post', Post::sole()->title_en);
        $this->assertSame(0, Tag::where('slug', 'nope')->count());
    }

    public function test_a_missing_tags_field_leaves_the_tags_and_an_empty_list_clears_them(): void
    {
        $this->editor();
        $post = Post::factory()->create(['slug' => 'tagged']);
        $post->tags()->attach(Tag::factory()->create(['slug' => 'mysql']));

        $this->putJson('/api/v1/posts/tagged', ['en' => ['title' => 'Changed']])->assertOk();
        $this->assertSame(['mysql'], $post->tags()->pluck('slug')->all());

        $this->putJson('/api/v1/posts/tagged', ['tags' => []])->assertOk()->assertJsonPath('meta.changes', ['tags']);
        $this->assertSame([], $post->tags()->pluck('slug')->all());
    }

    public function test_more_than_six_tags_is_refused(): void
    {
        $this->editor();

        $this->putJson('/api/v1/posts/a-clean-post', $this->body(['tags' => ['a', 'b', 'c', 'd', 'e', 'f', 'g']]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('tags');
    }

    public function test_a_field_the_api_does_not_know_is_refused(): void
    {
        $this->editor();

        $this->putJson('/api/v1/posts/a-clean-post', $this->body(['tag' => ['mysql'], 'author' => 'Someone else']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['tag', 'author']);
        $this->putJson('/api/v1/posts/a-clean-post', ['en' => ['titel' => 'A typo']])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('en.titel');
        $this->assertSame(0, Post::count());
    }

    public function test_a_slug_that_is_not_a_slug_does_not_match_a_route(): void
    {
        $this->editor();

        $this->putJson('/api/v1/posts/Not_A_Slug', $this->body())->assertNotFound();
    }

    public function test_a_draft_can_be_read_back_with_its_tags_and_a_missing_post_is_a_404(): void
    {
        $this->editor();
        $post = Post::factory()->create(['slug' => 'a-draft', 'title_en' => 'A draft']);
        $post->tags()->attach(Tag::factory()->create(['slug' => 'mysql']));

        $this->getJson('/api/v1/posts/a-draft')
            ->assertOk()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.tags', ['mysql'])
            ->assertJsonPath('data.en.title', 'A draft');
        $this->getJson('/api/v1/posts/no-such-post')->assertNotFound()->assertJsonPath('code', 'not_found');
    }

    public function test_the_public_list_never_shows_a_draft_written_through_the_api(): void
    {
        $this->editor();
        $this->putJson('/api/v1/posts/a-clean-post', $this->body())->assertCreated();

        $this->getJson('/api/posts')->assertOk()->assertExactJson([]);
    }

    public function test_the_authoring_api_is_limited_per_minute(): void
    {
        $this->editor();

        for ($i = 0; $i < 60; $i++) {
            $this->putJson('/api/v1/posts/a-clean-post?dry_run=1', $this->body())->assertOk();
        }

        $this->putJson('/api/v1/posts/a-clean-post?dry_run=1', $this->body())->assertStatus(429);
    }
}
