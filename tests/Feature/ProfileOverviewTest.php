<?php

namespace Tests\Feature;

use App\Filament\Pages\Profile;
use App\Models\Post;
use App\Models\User;
use App\Support\ProfileOverview;
use App\Support\Rbac;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ProfileOverviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_counts_cover_only_the_accounts_own_posts(): void
    {
        $writer = User::factory()->writer()->create();
        Post::factory()->count(2)->create(['user_id' => $writer->id]);
        Post::factory()->published()->create(['user_id' => $writer->id]);
        Post::factory()->count(3)->published()->create();
        Post::factory()->create(['user_id' => $writer->id, 'body_en' => 'one two three', 'body_id' => 'satu dua']);

        $overview = new ProfileOverview($writer);

        $this->assertSame(3, $overview->drafts());
        $this->assertSame(1, $overview->live());
    }

    public function test_words_are_counted_in_both_languages_of_every_own_post(): void
    {
        $writer = User::factory()->writer()->create();
        Post::factory()->create(['user_id' => $writer->id, 'body_en' => 'one two three', 'body_id' => 'satu dua']);
        Post::factory()->published()->create(['user_id' => $writer->id, 'body_en' => 'four', 'body_id' => 'empat lima']);
        Post::factory()->create(['body_en' => 'not counted at all', 'body_id' => 'tidak dihitung']);

        $this->assertSame(8, (new ProfileOverview($writer))->words());
    }

    public function test_incomplete_drafts_name_the_missing_languages(): void
    {
        $writer = User::factory()->writer()->create();
        $complete = Post::factory()->create(['user_id' => $writer->id]);
        $noIndonesian = Post::factory()->create(['user_id' => $writer->id, 'body_id' => '']);
        $noEnglish = Post::factory()->create(['user_id' => $writer->id, 'title_en' => '', 'description_en' => '']);
        $both = Post::factory()->create(['user_id' => $writer->id, 'body_en' => '', 'body_id' => '']);
        Post::factory()->published()->create(['user_id' => $writer->id]);
        Post::factory()->create(['body_id' => '']);

        $rows = (new ProfileOverview($writer))->incompleteDrafts()->mapWithKeys(
            fn (array $row): array => [$row['post']->id => $row['missing']],
        )->all();

        $this->assertArrayNotHasKey($complete->id, $rows);
        $this->assertSame(['Indonesian'], $rows[$noIndonesian->id]);
        $this->assertSame(['English'], $rows[$noEnglish->id]);
        $this->assertSame(['English', 'Indonesian'], $rows[$both->id]);
        $this->assertCount(3, $rows);
    }

    public function test_only_publishers_see_other_peoples_drafts_that_are_ready(): void
    {
        $other = User::factory()->writer()->create();
        $ready = Post::factory()->create(['user_id' => $other->id]);
        $imported = Post::factory()->create();
        Post::factory()->create(['user_id' => $other->id, 'body_id' => '']);
        Post::factory()->published()->create(['user_id' => $other->id]);

        $writer = User::factory()->writer()->create();
        $own = Post::factory()->create(['user_id' => $writer->id]);
        $this->assertCount(0, (new ProfileOverview($writer))->readyToPublish());

        foreach (['editor', 'admin'] as $role) {
            $ids = (new ProfileOverview(User::factory()->{$role}()->create()))->readyToPublish()->pluck('id')->all();

            $this->assertEqualsCanonicalizing([$ready->id, $imported->id, $own->id], $ids);
        }
    }

    public function test_an_editors_own_drafts_are_not_listed_as_ready_for_themself(): void
    {
        $editor = User::factory()->editor()->create();
        Post::factory()->create(['user_id' => $editor->id]);

        $this->assertCount(0, (new ProfileOverview($editor))->readyToPublish());
    }

    public function test_the_role_summary_follows_what_the_role_can_do(): void
    {
        $writer = (new ProfileOverview(User::factory()->writer()->create()))->abilities();
        $editor = (new ProfileOverview(User::factory()->editor()->create()))->abilities();
        $admin = new ProfileOverview(User::factory()->admin()->create());

        $this->assertContains(Rbac::SENTENCES[Rbac::POST_UPDATE_OWN], $writer);
        $this->assertNotContains(Rbac::SENTENCES[Rbac::POST_PUBLISH], $writer);
        $this->assertContains(Rbac::SENTENCES[Rbac::POST_PUBLISH], $editor);
        $this->assertNotContains(Rbac::SENTENCES[Rbac::USER_MANAGE], $editor);
        $this->assertSame(Rbac::SENTENCES[Rbac::USER_MANAGE], $admin->abilities()[array_key_last($admin->abilities())]);
        $this->assertSame('Admin', $admin->roleName());
    }

    public function test_every_permission_has_a_sentence(): void
    {
        $this->assertEqualsCanonicalizing(Rbac::permissions(), array_keys(Rbac::SENTENCES));
    }

    public function test_the_page_lists_only_the_accounts_own_posts(): void
    {
        $writer = User::factory()->writer()->create();
        $mine = Post::factory()->create(['user_id' => $writer->id]);
        $theirs = Post::factory()->create();
        $this->actingAs($writer);

        Livewire::test(Profile::class)
            ->assertCanSeeTableRecords([$mine])
            ->assertCanNotSeeTableRecords([$theirs]);
    }

    public function test_the_posts_list_offers_edit_on_a_draft_and_view_on_a_live_post(): void
    {
        $writer = User::factory()->writer()->create();
        $draft = Post::factory()->create(['user_id' => $writer->id]);
        $live = Post::factory()->published()->create(['user_id' => $writer->id]);
        $this->actingAs($writer);

        Livewire::test(Profile::class)
            ->assertActionVisible(TestAction::make('edit')->table($draft))
            ->assertActionHidden(TestAction::make('read')->table($draft))
            ->assertActionHidden(TestAction::make('edit')->table($live))
            ->assertActionVisible(TestAction::make('read')->table($live));
    }

    public function test_the_overview_renders_for_every_role(): void
    {
        foreach (['writer', 'editor', 'admin'] as $role) {
            $user = User::factory()->{$role}()->create();
            Post::factory()->create(['user_id' => $user->id, 'title_en' => "Draft by {$role}", 'body_id' => '']);
            $this->actingAs($user);

            Livewire::test(Profile::class)
                ->assertSee('Needs attention')
                ->assertSee("Draft by {$role}")
                ->assertSee('Indonesian finished');
        }
    }
}
