<?php

namespace Tests;

use App\Models\School;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

abstract class FunctionalTestCase extends TestCase
{
    use CreatesApplication;
    use RefreshDatabase;

    /** @var User */
    protected User $user;

    /**
     * Prepare a test DB data, create sample object would be used in all test methods.
     * @throws \Exception
     */
    protected function setUp(): void
    {
        // Call parent setUp which bootstraps laravel
        parent::setUp();

        try {
            $this->user = User::factory()->create([
                'email' => 'admin@demo.com',
                'school_id' => School::factory(['name' => 'School A'])->create()->getKey(),
            ]);
            $this->actingAs($this->user);
        } catch (\Exception $exception) {
            var_dump($exception->getMessage());
            throw $exception;
        }
    }
}
