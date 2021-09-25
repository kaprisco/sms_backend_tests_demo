<?php

namespace Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;

abstract class FunctionalTestCase extends TestCase
{
    use CreatesApplication;
    use RefreshDatabase;

    /** @var \App\Models\User */
    protected $user;

    /**
     * Prepare a test DB data, create sample object would be used in all test methods.
     * @throws \Exception
     */
    protected function setUp(): void
    {
        // Call parent setUp which bootstraps laravel
        parent::setUp();

        try {
            $this->user = \App\Models\User::factory()->create([
                'email' => 'admin@demo.com',
            ]);
            $this->actingAs($this->user);
        } catch (\Exception $exception) {
            var_dump($exception->getMessage());
            throw $exception;
        }
    }
}
