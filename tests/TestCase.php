<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // A token in a developer's .env must never make a test call GitHub.
        config(['services.github.token' => null]);
    }
}
