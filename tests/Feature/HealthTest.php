<?php

namespace Tests\Feature;

use Tests\TestCase;

class HealthTest extends TestCase
{
    public function test_the_app_answers_its_health_check(): void
    {
        $this->get('/up')->assertOk();
    }
}
