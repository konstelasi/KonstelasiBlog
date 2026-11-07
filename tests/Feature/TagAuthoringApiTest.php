<?php

namespace Tests\Feature;

use App\Jobs\RebuildSite;
use App\Models\Post;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TagAuthoringApiTest extends TestCase
{
    use RefreshDatabase;

    private function admin(array $abilities = ['tags:manage']): User
    {
        $user = User::factory()->admin()->create();
        Sanctum::actingAs($user, $abilities);

        return $user;
    }

    private function names(array $extra = []): array
    {
        return ['en' => 'Software development', 'id' => 'Pengembangan software', ...$extra];
    }

    public function test_a_request_without_a_token_is_refused(): void
    {
        $this->getJson('/api/v1/tags')->assertUnauthorized();
        $this->putJson('/api/v1/tags/software-development', $this->names())->assertUnauthorized();
        $this->assertSame(0, Tag::count());
    }

    public function test_a_token_that_only_writes_drafts_can_list_tags_but_not_create_one(): void
    {
        $this->admin(['posts:write']);
        Tag::factory()->create(['slug' => 'mysql']);

        $this->getJson('/api/v1/tags')->assertOk();
        $this->putJson('/api/v1/tags/software-development', $this->names())->assertForbidden();
        $this->assertSame(['mysql'], Tag::pluck('slug')->all());
    }

    public function test_a_token_that_only_manages_tags_cannot_touch_posts(): void
    {
        $this->admin(['tags:manage']);

        $this->getJson('/api/v1/posts/anything')->assertForbidden();
        $this->putJson('/api/v1/posts/a-post', ['en' => ['title' => 'T']])->assertForbidden();
        $this->assertSame(0, Post::count());
    }

    public function test_a_token_with_neither_ability_cannot_list_tags(): void
    {
        $this->admin(['posts:publish']);

        $this->getJson('/api/v1/tags')->assertForbidden();
    }

    public function test_it_creates_a_tag_and_starts_no_rebuild(): void
    {
        Bus::fake();
        $this->admin();

        $this->putJson('/api/v1/tags/software-development', $this->names())
            ->assertCreated()
            ->assertJsonPath('meta.action', 'created')
            ->assertJsonPath('meta.dry_run', false)
            ->assertJsonPath('data', ['slug' => 'software-development', 'en' => 'Software development', 'id' => 'Pengembangan software']);

        $tag = Tag::sole();
        $this->assertSame('Software development', $tag->name_en);
        $this->assertSame('Pengembangan software', $tag->name_id);
        Bus::assertNotDispatchedAfterResponse(RebuildSite::class);
    }

    public function test_the_same_request_twice_changes_nothing_the_second_time(): void
    {
        $this->admin();
        $this->putJson('/api/v1/tags/software-development', $this->names())->assertCreated();

        $this->putJson('/api/v1/tags/software-development', $this->names())
            ->assertOk()
            ->assertJsonPath('meta.action', 'unchanged');
        $this->assertSame(1, Tag::count());
    }

    public function test_other_names_for_an_existing_tag_are_refused_and_nothing_is_renamed(): void
    {
        $this->admin();
        Tag::factory()->create(['slug' => 'software-development', 'name_en' => 'Software development', 'name_id' => 'Pengembangan software']);

        $this->putJson('/api/v1/tags/software-development', $this->names(['en' => 'Software engineering']))
            ->assertStatus(409)
            ->assertJsonPath('code', 'tag_exists')
            ->assertJsonPath('errors.en.0', 'Software development');

        $this->assertSame('Software development', Tag::sole()->name_en);
    }

    public function test_an_account_that_may_not_manage_tags_cannot_create_one_even_with_the_ability(): void
    {
        $writer = User::factory()->writer()->create();
        Sanctum::actingAs($writer, ['tags:manage']);

        $this->putJson('/api/v1/tags/software-development', $this->names())
            ->assertForbidden()
            ->assertJsonPath('code', 'not_allowed');

        $this->assertSame(0, Tag::count());
    }

    public function test_an_editor_account_may_create_a_tag(): void
    {
        Sanctum::actingAs(User::factory()->editor()->create(), ['tags:manage']);

        $this->putJson('/api/v1/tags/software-development', $this->names())->assertCreated();
    }

    public function test_a_name_that_breaks_the_house_style_is_refused(): void
    {
        $this->admin();

        $this->putJson('/api/v1/tags/software-development', $this->names(['en' => 'Software: development']))
            ->assertUnprocessable()
            ->assertJsonPath('code', 'house_style')
            ->assertJsonStructure(['errors' => ['en']]);

        $this->assertSame(0, Tag::count());
    }

    public function test_both_names_are_required_and_nothing_else_is_accepted(): void
    {
        $this->admin();

        $this->putJson('/api/v1/tags/software-development', ['en' => 'Only English'])->assertUnprocessable()->assertJsonValidationErrors('id');
        $this->putJson('/api/v1/tags/software-development', $this->names(['label' => 'x']))->assertUnprocessable()->assertJsonValidationErrors('label');
        $this->putJson('/api/v1/tags/software-development', $this->names(['en' => str_repeat('a', 256)]))->assertUnprocessable()->assertJsonValidationErrors('en');
        $this->assertSame(0, Tag::count());
    }

    public function test_a_dry_run_checks_everything_and_creates_nothing(): void
    {
        $this->admin();

        $this->putJson('/api/v1/tags/software-development?dry_run=1', $this->names())
            ->assertOk()
            ->assertJsonPath('meta.action', 'created')
            ->assertJsonPath('meta.dry_run', true);
        $this->assertSame(0, Tag::count());

        $this->putJson('/api/v1/tags/software-development?dry_run=1', $this->names(['en' => 'Software: development']))
            ->assertUnprocessable()
            ->assertJsonPath('code', 'house_style');
    }

    public function test_a_slug_that_is_not_a_slug_does_not_match_a_route(): void
    {
        $this->admin();

        $this->putJson('/api/v1/tags/Not_A_Slug', $this->names())->assertNotFound();
    }

    public function test_the_list_gives_every_tag_by_slug_with_both_names(): void
    {
        $this->admin(['posts:write']);
        Tag::factory()->create(['slug' => 'stardust', 'name_en' => 'StarDust', 'name_id' => 'StarDust']);
        Tag::factory()->create(['slug' => 'mysql', 'name_en' => 'MySQL', 'name_id' => 'MySQL']);

        $this->getJson('/api/v1/tags')
            ->assertOk()
            ->assertExactJson(['data' => [
                ['slug' => 'mysql', 'en' => 'MySQL', 'id' => 'MySQL'],
                ['slug' => 'stardust', 'en' => 'StarDust', 'id' => 'StarDust'],
            ]]);
    }

    public function test_a_post_still_cannot_make_a_tag_even_for_a_token_that_can(): void
    {
        $this->admin(['posts:write', 'tags:manage']);

        $this->putJson('/api/v1/posts/a-post', ['en' => ['title' => 'A post'], 'tags' => ['made-up']])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'unknown_tag');

        $this->assertSame(0, Tag::count());
        $this->assertSame(0, Post::count());
    }
}
