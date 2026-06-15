<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A basic test example.
     */
    public function test_the_application_redirects_guests_to_login(): void
    {
        $response = $this->get('https://platform.stelfaro.com');

        $response->assertRedirect('https://platform.stelfaro.com/login');
    }
}
