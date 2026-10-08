<?php

namespace Tests\Feature;

use App\Enums\PostStatus;
use App\Jobs\RebuildSite;
use App\Models\Post;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SiteRebuildTest extends TestCase
{
    use RefreshDatabase;

    private const URL = 'https://api.github.com/repos/konstelasi/KonstelasiWebsite/actions/workflows/deploy.yml/dispatches';

    public function test_publishing_a_post_starts_one_rebuild(): void
    {
        Bus::fake();

        Post::factory()->published()->create();

        Bus::assertDispatchedAfterResponse(RebuildSite::class, 1);
    }

    public function test_saving_a_draft_starts_nothing(): void
    {
        Bus::fake();

        Post::factory()->create();

        Bus::assertNotDispatchedAfterResponse(RebuildSite::class);
    }

    public function test_editing_a_published_post_starts_a_rebuild(): void
    {
        $post = Post::factory()->published()->create();
        Bus::fake();

        $post->update(['title_en' => 'A corrected title']);

        Bus::assertDispatchedAfterResponse(RebuildSite::class, 1);
    }

    public function test_unpublishing_a_post_starts_a_rebuild(): void
    {
        $post = Post::factory()->published()->create();
        Bus::fake();

        $post->update(['status' => PostStatus::Draft]);

        Bus::assertDispatchedAfterResponse(RebuildSite::class, 1);
    }

    public function test_editing_a_draft_starts_nothing(): void
    {
        $draft = Post::factory()->create();
        Bus::fake();

        $draft->update(['title_en' => 'Still a draft']);

        Bus::assertNotDispatchedAfterResponse(RebuildSite::class);
    }

    public function test_deleting_a_published_post_starts_a_rebuild_but_a_draft_does_not(): void
    {
        $draft = Post::factory()->create();
        $published = Post::factory()->published()->create();
        Bus::fake();

        $draft->delete();
        Bus::assertNotDispatchedAfterResponse(RebuildSite::class);

        $published->delete();
        Bus::assertDispatchedAfterResponse(RebuildSite::class, 1);
    }

    public function test_the_job_asks_github_to_run_the_deploy_workflow(): void
    {
        config(['services.github.token' => 'test-token']);
        Http::fake(['api.github.com/*' => Http::response('', 204)]);

        (new RebuildSite)->handle();

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request) => $request->url() === self::URL
            && $request['ref'] === 'main'
            && $request->hasHeader('Authorization', 'Bearer test-token'));
    }

    public function test_without_a_token_the_job_sends_nothing(): void
    {
        config(['services.github.token' => null]);
        Http::fake();

        (new RebuildSite)->handle();

        Http::assertNothingSent();
    }

    public function test_a_github_error_is_swallowed(): void
    {
        config(['services.github.token' => 'test-token']);
        Http::fake(['api.github.com/*' => Http::response('boom', 500)]);

        (new RebuildSite)->handle();

        Http::assertSentCount(1);
    }

    public function test_a_network_failure_is_swallowed(): void
    {
        config(['services.github.token' => 'test-token']);
        Http::fake(['api.github.com/*' => fn () => throw new ConnectionException('offline')]);

        (new RebuildSite)->handle();

        $this->addToAssertionCount(1);
    }
}
