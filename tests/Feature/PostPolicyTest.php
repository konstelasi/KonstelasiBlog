<?php

namespace Tests\Feature;

use App\Filament\Resources\Posts\Pages\EditPost;
use App\Filament\Resources\Posts\Pages\ListPosts;
use App\Filament\Resources\Posts\Pages\ViewPost;
use App\Models\Post;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PostPolicyTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Who may update and delete which post. Everyone with a role may view and
     * create, so those are checked once in the tests below.
     *
     * @return array<string, array{string, string, bool}>
     */
    public static function matrix(): array
    {
        $rows = [];

        foreach ([
            // role, post, may update and delete it
            ['writer', 'own draft', true],
            ['writer', 'own live', false],
            ['writer', 'other draft', false],
            ['writer', 'other live', false],
            ['writer', 'no writer draft', false],
            ['writer', 'no writer live', false],
            ['editor', 'own draft', true],
            ['editor', 'own live', true],
            ['editor', 'other draft', true],
            ['editor', 'other live', true],
            ['editor', 'no writer draft', true],
            ['editor', 'no writer live', true],
            ['admin', 'own draft', true],
            ['admin', 'own live', true],
            ['admin', 'other draft', true],
            ['admin', 'other live', true],
            ['admin', 'no writer draft', true],
            ['admin', 'no writer live', true],
        ] as $row) {
            $rows["{$row[0]}, {$row[1]}"] = $row;
        }

        return $rows;
    }

    #[DataProvider('matrix')]
    public function test_the_policy_follows_role_ownership_and_status(string $role, string $which, bool $allowed): void
    {
        $user = User::factory()->{$role}()->create();
        $post = $this->postFor($which, $user);

        $this->assertTrue($user->can('view', $post), 'view');
        $this->assertTrue($user->can('viewAny', Post::class), 'viewAny');
        $this->assertTrue($user->can('create', Post::class), 'create');
        $this->assertSame($allowed, $user->can('update', $post), 'update');
        $this->assertSame($allowed, $user->can('delete', $post), 'delete');
    }

    public function test_only_editors_and_admins_may_publish(): void
    {
        $this->assertFalse(User::factory()->writer()->create()->can('publish', Post::class));
        $this->assertTrue(User::factory()->editor()->create()->can('publish', Post::class));
        $this->assertTrue(User::factory()->admin()->create()->can('publish', Post::class));
    }

    public function test_an_account_with_no_role_can_do_nothing(): void
    {
        $user = User::factory()->create();
        $post = Post::factory()->create(['user_id' => $user->id]);

        $this->assertFalse($user->can('viewAny', Post::class));
        $this->assertFalse($user->can('view', $post));
        $this->assertFalse($user->can('create', Post::class));
        $this->assertFalse($user->can('update', $post));
        $this->assertFalse($user->can('delete', $post));
        $this->assertFalse($user->can('deleteAny', Post::class));
        $this->assertFalse($user->can('publish', Post::class));
    }

    public function test_an_account_with_no_role_is_refused_at_the_admin(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get('/admin')->assertForbidden();
        $this->get('/admin/posts')->assertForbidden();
    }

    public function test_every_role_gets_into_the_admin(): void
    {
        foreach (['writer', 'editor', 'admin'] as $role) {
            $this->actingAs(User::factory()->{$role}()->create());

            $this->get('/admin/posts')->assertOk();
        }
    }

    public function test_a_writer_reads_someone_elses_post_but_cannot_edit_it(): void
    {
        $this->actingAs(User::factory()->writer()->create());
        $post = Post::factory()->create(['title_en' => 'Not mine']);

        Livewire::test(ViewPost::class, ['record' => $post->getRouteKey()])
            ->assertSuccessful()
            ->assertSchemaStateSet(['title_en' => 'Not mine']);

        Livewire::test(EditPost::class, ['record' => $post->getRouteKey()])
            ->assertForbidden();
    }

    public function test_a_writer_cannot_edit_their_own_live_post(): void
    {
        $writer = User::factory()->writer()->create();
        $post = Post::factory()->published()->create(['user_id' => $writer->id]);
        $this->actingAs($writer);

        Livewire::test(EditPost::class, ['record' => $post->getRouteKey()])->assertForbidden();
        Livewire::test(ViewPost::class, ['record' => $post->getRouteKey()])->assertSuccessful();
    }

    public function test_the_list_offers_edit_or_view_by_what_the_person_may_do(): void
    {
        $writer = User::factory()->writer()->create();
        $this->actingAs($writer);
        $mine = Post::factory()->create(['user_id' => $writer->id]);
        $theirs = Post::factory()->create();

        Livewire::test(ListPosts::class)
            ->assertCanSeeTableRecords([$mine, $theirs])
            ->assertActionVisible(TestAction::make('edit')->table($mine))
            ->assertActionHidden(TestAction::make('read')->table($mine))
            ->assertActionHidden(TestAction::make('edit')->table($theirs))
            ->assertActionVisible(TestAction::make('read')->table($theirs));
    }

    public function test_a_row_opens_the_page_the_person_can_use(): void
    {
        $writer = User::factory()->writer()->create();
        $mine = Post::factory()->create(['user_id' => $writer->id]);
        $liveOfTheirs = Post::factory()->published()->create();
        $this->actingAs($writer);

        $table = Livewire::test(ListPosts::class)->instance()->getTable();

        $this->assertStringEndsWith("/posts/{$mine->id}/edit", $table->getRecordUrl($mine));
        $this->assertStringEndsWith("/posts/{$liveOfTheirs->id}", $table->getRecordUrl($liveOfTheirs));
    }

    public function test_a_writer_cannot_delete_other_posts_in_bulk(): void
    {
        $writer = User::factory()->writer()->create();
        $mine = Post::factory()->create(['user_id' => $writer->id]);
        $theirs = Post::factory()->create();
        $live = Post::factory()->published()->create(['user_id' => $writer->id]);
        $this->actingAs($writer);

        Livewire::test(ListPosts::class)
            ->selectTableRecords([$mine->getKey(), $theirs->getKey(), $live->getKey()])
            ->callAction(TestAction::make('delete')->table()->bulk());

        $this->assertModelMissing($mine);
        $this->assertModelExists($theirs);
        $this->assertModelExists($live);
    }

    public function test_an_editor_deletes_any_post_in_bulk(): void
    {
        $this->actingAs(User::factory()->editor()->create());
        $posts = [Post::factory()->create(), Post::factory()->published()->create()];

        Livewire::test(ListPosts::class)
            ->selectTableRecords(array_map(fn (Post $post) => $post->getKey(), $posts))
            ->callAction(TestAction::make('delete')->table()->bulk());

        $this->assertDatabaseCount('posts', 0);
    }

    private function postFor(string $which, User $user): Post
    {
        $other = User::factory()->writer()->create();
        $factory = str_ends_with($which, 'live') ? Post::factory()->published() : Post::factory();

        return $factory->create(['user_id' => match (true) {
            str_starts_with($which, 'own') => $user->id,
            str_starts_with($which, 'other') => $other->id,
            default => null,
        }]);
    }
}
