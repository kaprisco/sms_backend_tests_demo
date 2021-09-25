<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\Response;
use Tests\TestCase;

class HomePageTest extends TestCase
{
    use DatabaseMigrations;

    /**
     *
     * @return void
     */
    public function testMainPage()
    {
        // Should redirect to login page.
        $this->get('/dashboard')
            ->assertStatus(Response::HTTP_FOUND)
            ->assertRedirect('/login');

        $user = User::factory()->create();
        $this->actingAs($user);

        $this->get('/dashboard')
            ->assertStatus(Response::HTTP_OK);
    }
}
