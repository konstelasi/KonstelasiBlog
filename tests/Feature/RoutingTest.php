<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoutingTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_home_page_redirects_to_the_public_blog(): void
    {
        $this->get('/')
            ->assertStatus(301)
            ->assertRedirect('https://konstelasi.co.id/blog/');
    }

    public function test_every_response_asks_search_engines_to_stay_away(): void
    {
        foreach (['/', '/api/posts', '/admin/login', '/no-such-page'] as $uri) {
            $this->get($uri)->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        }
    }

    public function test_robots_txt_disallows_everything(): void
    {
        $robots = file_get_contents(public_path('robots.txt'));

        $this->assertMatchesRegularExpression('/^User-agent: \*$/m', $robots);
        $this->assertMatchesRegularExpression('/^Disallow: \/$/m', $robots);
    }

    public function test_nobody_can_register(): void
    {
        $this->get('/admin/register')->assertNotFound();
    }
}
