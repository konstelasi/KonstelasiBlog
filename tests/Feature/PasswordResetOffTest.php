<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * With no real mailer (the test default) there is no "Forgot password?" link
 * and no reset route, because the link would only go to the log.
 */
class PasswordResetOffTest extends TestCase
{
    use RefreshDatabase;

    public function test_there_is_no_reset_without_a_mailer(): void
    {
        $this->get('/admin/login')->assertOk()->assertDontSee('password-reset', false);
        $this->get('/admin/password-reset/request')->assertNotFound();
    }
}
