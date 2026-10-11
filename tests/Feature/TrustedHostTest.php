<?php

namespace Tests\Feature;

use Illuminate\Http\Middleware\TrustHosts;
use Tests\TestCase;

/**
 * The reset link is built from the request's host, so the app answers only
 * the host in `APP_URL`. Laravel skips the check in tests and when `APP_ENV`
 * is `local`, so this reads the list the middleware would enforce.
 */
class TrustedHostTest extends TestCase
{
    public function test_only_the_app_url_host_is_trusted(): void
    {
        config(['app.url' => 'https://blog.konstelasi.co.id']);

        $hosts = app(TrustHosts::class)->hosts();

        $this->assertSame(['^blog\.konstelasi\.co\.id$'], $hosts);
        $this->assertSame(1, preg_match('{'.$hosts[0].'}i', 'blog.konstelasi.co.id'));
        $this->assertSame(0, preg_match('{'.$hosts[0].'}i', 'evil.example'));
        $this->assertSame(0, preg_match('{'.$hosts[0].'}i', 'blog.konstelasi.co.id.evil.example'));
        $this->assertSame(0, preg_match('{'.$hosts[0].'}i', 'x.blog.konstelasi.co.id'));
    }
}
