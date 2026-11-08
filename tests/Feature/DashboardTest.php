<?php

namespace Tests\Feature;

use App\Jobs\RebuildSite;
use App\Models\Post;
use App\Models\User;
use App\Support\DashboardOverview;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_figures_count_the_whole_blog_and_only_live_words(): void
    {
        $writer = User::factory()->writer()->create();
        Post::factory()->published('2026-09-01')->create(['body_en' => 'one two three', 'body_id' => 'satu dua']);
        Post::factory()->published('2026-10-01')->create(['body_en' => 'four', 'body_id' => 'lima enam']);
        Post::factory()->count(2)->create(['body_en' => 'never counted at all', 'body_id' => 'tidak dihitung']);

        $overview = new DashboardOverview($writer);

        $this->assertSame(2, $overview->live());
        $this->assertSame(2, $overview->drafts());
        $this->assertSame(8, $overview->liveWords());
        $this->assertSame('2026-10-01', $overview->lastPublished()->format('Y-m-d'));
    }

    public function test_there_is_no_last_published_date_before_the_first_post(): void
    {
        $this->assertNull((new DashboardOverview(User::factory()->writer()->create()))->lastPublished());
    }

    public function test_the_monthly_series_has_twelve_months_with_zeros_filled_in(): void
    {
        $this->travelTo('2026-10-09 12:00:00');
        Post::factory()->published('2026-10-02')->create();
        Post::factory()->published('2026-10-05')->create();
        Post::factory()->published('2026-05-15')->create();
        Post::factory()->published('2025-11-01')->create();
        Post::factory()->published('2025-10-31')->create();
        Post::factory()->create();

        $months = (new DashboardOverview(User::factory()->writer()->create()))->monthlyPublished();

        $this->assertCount(12, $months);
        $this->assertSame('2025-11', array_key_first($months));
        $this->assertSame('2026-10', array_key_last($months));
        $this->assertSame(2, $months['2026-10']);
        $this->assertSame(1, $months['2026-05']);
        $this->assertSame(1, $months['2025-11']);
        $this->assertSame(0, $months['2026-07']);
        $this->assertSame(4, array_sum($months));
    }

    public function test_only_publishers_see_ready_drafts_and_never_their_own_oldest_first(): void
    {
        $editor = User::factory()->editor()->create();
        $other = User::factory()->writer()->create();
        $newer = Post::factory()->create(['user_id' => $other->id, 'updated_at' => now()->subDay()]);
        // An imported draft has no writer, so it is nobody's desk but waits for a decision.
        $imported = Post::factory()->create(['user_id' => null, 'updated_at' => now()->subDays(5)]);
        Post::factory()->create(['user_id' => $editor->id, 'updated_at' => now()->subDays(9)]);
        Post::factory()->create(['body_id' => '']);
        Post::factory()->published()->create();

        $this->assertSame([$imported->id, $newer->id], (new DashboardOverview($editor))->readyToPublish()->pluck('id')->all());
        $this->assertCount(0, (new DashboardOverview($other))->readyToPublish());
    }

    public function test_a_publisher_sees_other_peoples_half_done_drafts_and_a_writer_sees_none(): void
    {
        $writer = User::factory()->writer()->create();
        $editor = User::factory()->editor()->create();
        $mine = Post::factory()->create(['user_id' => $writer->id, 'body_id' => '']);
        $theirs = Post::factory()->create(['user_id' => User::factory()->writer()->create()->id, 'title_en' => '', 'description_en' => '']);
        $editors = Post::factory()->create(['user_id' => $editor->id, 'body_id' => '']);
        $imported = Post::factory()->create(['user_id' => null, 'body_en' => '']);
        Post::factory()->create();

        $forEditor = (new DashboardOverview($editor))->halfDone();

        // The editor's own half-done draft is on their desk, not in this list.
        $this->assertEqualsCanonicalizing([$mine->id, $theirs->id, $imported->id], $forEditor->pluck('post.id')->all());
        $this->assertNotContains($editors->id, $forEditor->pluck('post.id')->all());
        $this->assertCount(0, (new DashboardOverview($writer))->halfDone());
    }

    public function test_your_drafts_are_only_your_own_and_the_one_edited_last_comes_first(): void
    {
        $writer = User::factory()->writer()->create();
        $older = Post::factory()->create(['user_id' => $writer->id, 'body_id' => '', 'updated_at' => now()->subDays(3)]);
        $newest = Post::factory()->create(['user_id' => $writer->id, 'updated_at' => now()->subHour()]);
        Post::factory()->create(['user_id' => User::factory()->writer()->create()->id]);
        Post::factory()->create(['user_id' => null]);
        Post::factory()->published()->create(['user_id' => $writer->id]);

        $drafts = (new DashboardOverview($writer))->yourDrafts();

        $this->assertSame([$newest->id, $older->id], $drafts->pluck('post.id')->all());
        $this->assertSame([[], ['Indonesian']], $drafts->pluck('missing')->all());
    }

    public function test_your_live_posts_are_only_your_own_newest_first_with_a_count(): void
    {
        $writer = User::factory()->writer()->create();
        $other = User::factory()->writer()->create();
        $first = Post::factory()->published('2026-08-01')->create(['user_id' => $writer->id]);
        $second = Post::factory()->published('2026-10-01')->create(['user_id' => $writer->id]);
        Post::factory()->published('2026-09-01')->create(['user_id' => $other->id]);
        Post::factory()->create(['user_id' => $writer->id]);

        $overview = new DashboardOverview($writer);

        $this->assertSame([$second->id, $first->id], $overview->yourLivePosts()->pluck('id')->all());
        $this->assertSame(2, $overview->yourLiveCount());
    }

    public function test_a_long_list_is_cut_at_eight_but_the_live_count_stays_whole(): void
    {
        $writer = User::factory()->writer()->create();
        Post::factory()->count(10)->published()->create(['user_id' => $writer->id]);
        Post::factory()->count(10)->create(['user_id' => $writer->id]);

        $overview = new DashboardOverview($writer);

        $this->assertCount(8, $overview->yourLivePosts());
        $this->assertCount(8, $overview->yourDrafts());
        $this->assertSame(10, $overview->yourLiveCount());
    }

    public function test_the_two_factor_list_is_for_admins_and_skips_accounts_that_cannot_sign_in(): void
    {
        $admin = User::factory()->admin()->create();
        $without = User::factory()->writer()->create(['name' => 'No second step']);
        User::factory()->editor()->create(['app_authentication_secret' => 'JBSWY3DPEHPK3PXP']);
        User::factory()->create(['name' => 'Has no role']);

        $overview = new DashboardOverview($admin);

        $this->assertSame(3, $overview->accountCount());
        $this->assertEqualsCanonicalizing([$admin->id, $without->id], $overview->withoutTwoFactor()->pluck('id')->all());
        $this->assertCount(0, (new DashboardOverview(User::factory()->editor()->create()))->withoutTwoFactor());
    }

    public function test_the_dashboard_shows_each_role_only_its_own_blocks(): void
    {
        Post::factory()->create(['title_en' => 'Waiting for an editor']);
        Post::factory()->create(['title_en' => 'Missing its Indonesian', 'body_id' => '']);

        $this->actingAs(User::factory()->writer()->create());
        $this->get('/admin')->assertOk()
            ->assertSee('Published, last 12 months')
            ->assertDontSee('Waiting for an editor')
            ->assertDontSee('Missing its Indonesian')
            ->assertDontSee('Two-factor sign-in')
            ->assertDontSee('asked to rebuild')
            ->assertDontSee('Automatic rebuilds');

        $this->actingAs(User::factory()->editor()->create());
        $this->get('/admin')->assertOk()
            ->assertSee('Ready to publish')
            ->assertSee('Waiting for an editor')
            ->assertSee('Missing its Indonesian')
            ->assertSee('Automatic rebuilds are not set up')
            ->assertDontSee('Two-factor sign-in');

        $this->actingAs(User::factory()->admin()->create());
        $this->get('/admin')->assertOk()
            ->assertSee('Two-factor sign-in')
            ->assertSee('Automatic rebuilds are not set up');
    }

    public function test_the_site_line_says_when_the_rebuild_was_last_requested(): void
    {
        config(['services.github.token' => 'test-token']);
        Cache::forever(RebuildSite::REQUESTED_KEY, now()->subMinutes(7)->timestamp);
        $this->actingAs(User::factory()->editor()->create());

        $this->get('/admin')->assertOk()
            ->assertSee('last asked to rebuild 7 minutes ago')
            ->assertSee('actions/workflows/deploy.yml');
    }

    public function test_an_empty_blog_still_renders(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        $this->get('/admin')->assertOk()
            ->assertSee('Nothing published in the last 12 months.')
            ->assertSee('Nothing yet');
    }

    public function test_the_page_is_a_desk_with_a_new_post_button(): void
    {
        foreach (['writer', 'editor', 'admin'] as $role) {
            $this->actingAs(User::factory()->{$role}()->create());

            $this->get('/admin')->assertOk()
                ->assertSee('Your desk')
                ->assertSee('New post')
                ->assertSee('/admin/posts/create', false)
                ->assertSee('desk-main', false)
                ->assertSee('desk-side', false);
        }
    }

    public function test_the_desk_leads_with_the_draft_edited_last_and_lists_the_rest(): void
    {
        $writer = User::factory()->writer()->create();
        Post::factory()->create(['user_id' => $writer->id, 'title_en' => 'The draft to pick up', 'body_id' => '', 'updated_at' => now()->subHour()]);
        Post::factory()->create(['user_id' => $writer->id, 'title_en' => 'An older draft', 'body_en' => '', 'updated_at' => now()->subDays(4)]);
        Post::factory()->create(['title_en' => 'Somebody else draft']);
        $this->actingAs($writer);

        $this->get('/admin')->assertOk()
            ->assertSeeInOrder(['Your drafts', 'The draft to pick up', 'Indonesian is not complete yet.', 'Continue writing', 'An older draft'])
            ->assertSee('needs English')
            ->assertDontSee('Somebody else draft');
    }

    public function test_a_complete_lead_draft_says_who_can_publish_it(): void
    {
        $writer = User::factory()->writer()->create();
        Post::factory()->create(['user_id' => $writer->id]);
        $this->actingAs($writer);
        $this->get('/admin')->assertOk()->assertSee('An Editor or an Admin can publish it.');

        $editor = User::factory()->editor()->create();
        Post::factory()->create(['user_id' => $editor->id]);
        $this->actingAs($editor);
        $this->get('/admin')->assertOk()->assertSee('You can publish it.');
    }

    public function test_a_new_account_is_invited_to_start_a_post(): void
    {
        $this->actingAs(User::factory()->writer()->create());

        $this->get('/admin')->assertOk()
            ->assertSee('Nothing in progress.')
            ->assertSee('Start a post')
            ->assertSee('None of your posts is live yet.');
    }

    public function test_the_live_posts_are_the_accounts_own_with_dates_and_links_to_the_site(): void
    {
        config(['services.site.url' => 'https://example.test']);
        $writer = User::factory()->writer()->create();
        Post::factory()->published('2026-10-01 12:00:00')->create(['user_id' => $writer->id, 'slug' => 'my-live-post', 'title_en' => 'My live post']);
        Post::factory()->published()->create(['slug' => 'not-mine', 'title_en' => 'Not mine']);
        $this->actingAs($writer);

        $this->get('/admin')->assertOk()
            ->assertSee('Your live posts')
            ->assertSee('My live post')
            ->assertSee('1 Oct 2026')
            ->assertSee('https://example.test/blog/my-live-post/', false)
            ->assertSee('https://example.test/id/blog/my-live-post/', false)
            ->assertDontSee('Not mine');
    }

    public function test_older_live_posts_beyond_the_list_are_counted_with_a_link(): void
    {
        $writer = User::factory()->writer()->create();
        Post::factory()->count(10)->published()->create(['user_id' => $writer->id]);
        $this->actingAs($writer);

        $this->get('/admin')->assertOk()
            ->assertSee('2 older posts are in')
            ->assertSee('the Posts list');
    }

    public function test_the_side_column_has_the_blog_figures_for_everyone(): void
    {
        Post::factory()->published()->create(['body_en' => 'one two', 'body_id' => 'tiga']);
        Post::factory()->create();
        $this->actingAs(User::factory()->writer()->create());

        $this->get('/admin')->assertOk()
            ->assertSeeInOrder(['The blog', 'Live post', 'Draft', 'Words live, both languages', '3', 'Last published']);
    }

    public function test_only_a_publisher_has_the_waiting_list_and_it_leaves_out_their_own_drafts(): void
    {
        $editor = User::factory()->editor()->create();
        Post::factory()->create(['user_id' => $editor->id, 'title_en' => 'My own complete draft']);
        Post::factory()->create(['user_id' => User::factory()->writer()->create()->id, 'title_en' => 'A writer draft']);

        $this->actingAs($editor);
        $this->get('/admin')->assertOk()
            ->assertSee('Ready to publish')
            ->assertSee('A writer draft')
            // It is on their desk, so the waiting list does not repeat it.
            ->assertSee('My own complete draft');
        $this->assertSame(1, substr_count($this->get('/admin')->getContent(), 'My own complete draft'));

        $this->actingAs(User::factory()->writer()->create());
        $this->get('/admin')->assertOk()->assertDontSee('Ready to publish')->assertDontSee('Waiting for you');
    }

    public function test_an_empty_waiting_list_says_so_for_a_publisher(): void
    {
        $this->actingAs(User::factory()->editor()->create());

        $this->get('/admin')->assertOk()->assertSee('No one else has a draft waiting for a decision.');
    }
}
