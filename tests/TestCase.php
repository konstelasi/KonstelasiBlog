<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // The admin theme is built by Vite after the tests run, so a clean
        // checkout has no manifest. The tests check behaviour, not styling.
        $this->withoutVite();

        // A token in a developer's .env must never make a test call GitHub.
        config(['services.github.token' => null]);
    }
}
