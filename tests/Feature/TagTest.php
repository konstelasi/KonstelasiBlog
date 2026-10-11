<?php

namespace Tests\Feature;

use App\Filament\Resources\Posts\Pages\CreatePost;
use App\Filament\Resources\Posts\Pages\EditPost;
use App\Filament\Resources\Tags\Pages\CreateTag;
use App\Filament\Resources\Tags\Pages\EditTag;
use App\Filament\Resources\Tags\Pages\ListTags;
use App\Jobs\RebuildSite;
use App\Models\Post;
use App\Models\Tag;
use App\Models\User;
use Filament\Forms\Components\Select;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TagTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_api_lists_each_posts_tags_by_english_name(): void
    {
        $post = Post::factory()->published()->create();
        $post->tags()->attach([
            Tag::factory()->create(['slug' => 'security', 'name_en' => 'Security', 'name_id' => 'Keamanan'])->id,
            Tag::factory()->create(['slug' => 'ai', 'name_en' => 'AI', 'name_id' => 'AI'])->id,
        ]);

        $this->getJson('/api/posts')
            ->assertOk()
            ->assertJsonPath('0.tags', [
                ['slug' => 'ai', 'en' => 'AI', 'id' => 'AI'],
                ['slug' => 'security', 'en' => 'Security', 'id' => 'Keamanan'],
            ]);
    }

    public function test_a_post_without_tags_has_an_empty_list_and_a_draft_stays_out(): void
    {
        Post::factory()->published()->create();
        Post::factory()->create()->tags()->attach(Tag::factory()->create());

        $this->getJson('/api/posts')
            ->assertJsonCount(1)
            ->assertJsonPath('0.tags', []);
    }

    public function test_tag_names_follow_the_house_style(): void
    {
        $this->actingAs(User::factory()->editor()->create());

        Livewire::test(CreateTag::class)
            ->fillForm(['name_en' => 'Design: notes', 'name_id' => 'Desain', 'slug' => 'design'])
            ->call('create')
            ->assertHasFormErrors(['name_en']);

        $this->assertSame(0, Tag::count());
    }

    public function test_the_slug_is_filled_in_from_the_english_name_and_must_be_unique(): void
    {
        $this->actingAs(User::factory()->editor()->create());
        Tag::factory()->create(['slug' => 'open-source']);

        Livewire::test(CreateTag::class)
            ->fillForm(['name_en' => 'Open source'])
            ->assertSchemaStateSet(['slug' => 'open-source'])
            ->fillForm(['name_id' => 'Open source'])
            ->call('create')
            ->assertHasFormErrors(['slug' => 'unique']);
    }

    /** @return array<string, array{string, int}> */
    public static function roles(): array
    {
        return ['writer' => ['writer', 403], 'editor' => ['editor', 200], 'admin' => ['admin', 200]];
    }

    #[DataProvider('roles')]
    public function test_only_editors_and_admins_see_the_tag_list(string $role, int $status): void
    {
        $this->actingAs(User::factory()->{$role}()->create());

        $this->get('/admin/tags')->assertStatus($status);
        $this->get('/admin/tags/create')->assertStatus($status);
    }

    public function test_a_writer_picks_existing_tags_on_a_new_draft(): void
    {
        $writer = User::factory()->writer()->create();
        $this->actingAs($writer);
        $tag = Tag::factory()->create();

        Livewire::test(CreatePost::class)
            ->fillForm([
                'slug' => 'tagged',
                'title_en' => 'Tagged',
                'tags' => [$tag->id],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame([$tag->id], Post::sole()->tags->modelKeys());
        $this->assertSame(1, Tag::count());
    }

    /** @return array<string, array{string, bool}> */
    public static function createOptionByRole(): array
    {
        return ['writer' => ['writer', false], 'editor' => ['editor', true], 'admin' => ['admin', true]];
    }

    #[DataProvider('createOptionByRole')]
    public function test_only_tag_managers_get_the_create_tag_option_on_a_post(string $role, bool $offered): void
    {
        $this->actingAs(User::factory()->{$role}()->create());

        $select = collect(Livewire::test(CreatePost::class)->instance()->getSchema('form')->getFlatComponents())
            ->first(fn ($component): bool => $component instanceof Select && $component->getName() === 'tags');

        $this->assertSame($offered, $select->hasCreateOptionActionFormSchema());
    }

    public function test_an_editor_can_change_the_tags_of_a_live_post_and_the_site_rebuilds(): void
    {
        $post = Post::factory()->published()->create();
        $old = Tag::factory()->create();
        $new = Tag::factory()->create();
        $post->tags()->attach($old);
        $this->actingAs(User::factory()->editor()->create());
        Bus::fake();

        Livewire::test(EditPost::class, ['record' => $post->getRouteKey()])
            ->fillForm(['tags' => [$new->id]])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame([$new->id], $post->refresh()->tags->modelKeys());
        Bus::assertDispatchedAfterResponse(RebuildSite::class);
    }

    public function test_renaming_a_tag_on_a_live_post_rebuilds_but_an_unused_tag_does_not(): void
    {
        $this->actingAs(User::factory()->editor()->create());
        $used = Tag::factory()->create();
        Post::factory()->published()->create()->tags()->attach($used);
        $unused = Tag::factory()->create();
        Bus::fake();

        Livewire::test(EditTag::class, ['record' => $used->getRouteKey()])
            ->fillForm(['name_en' => 'A better name'])
            ->call('save');
        Bus::assertDispatchedAfterResponse(RebuildSite::class, 1);

        Livewire::test(EditTag::class, ['record' => $unused->getRouteKey()])
            ->fillForm(['name_en' => 'Another name'])
            ->call('save');
        Bus::assertDispatchedAfterResponse(RebuildSite::class, 1);
    }

    public function test_deleting_a_tag_on_a_live_post_rebuilds_and_frees_the_post(): void
    {
        $tag = Tag::factory()->create();
        $post = Post::factory()->published()->create();
        $post->tags()->attach($tag);
        Bus::fake();

        $tag->delete();

        Bus::assertDispatchedAfterResponse(RebuildSite::class, 1);
        $this->assertSame(0, $post->tags()->count());
    }

    public function test_the_slug_locks_once_a_live_post_carries_the_tag(): void
    {
        $this->actingAs(User::factory()->editor()->create());
        $tag = Tag::factory()->create();

        Livewire::test(EditTag::class, ['record' => $tag->getRouteKey()])
            ->assertFormFieldEnabled('slug');

        Post::factory()->published()->create()->tags()->attach($tag);

        Livewire::test(EditTag::class, ['record' => $tag->getRouteKey()])
            ->assertFormFieldDisabled('slug');
    }

    public function test_the_tag_list_counts_live_and_all_posts(): void
    {
        $this->actingAs(User::factory()->editor()->create());
        $tag = Tag::factory()->create();
        $tag->posts()->attach([
            Post::factory()->published()->create()->id,
            Post::factory()->create()->id,
        ]);

        Livewire::test(ListTags::class)
            ->assertCanSeeTableRecords([$tag])
            ->assertTableColumnStateSet('live_posts_count', 1, $tag)
            ->assertTableColumnStateSet('posts_count', 2, $tag);
    }
}
