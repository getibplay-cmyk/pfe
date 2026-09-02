<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * A basic test example.
     */
    public function test_public_home_can_be_rendered(): void
    {
        $this->get('/')->assertOk()->assertSee('Une vue claire de chaque véhicule, contrat et décision.');
    }
}
