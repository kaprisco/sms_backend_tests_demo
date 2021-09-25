<?php

namespace Tests\Unit;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserModelTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Run Database Seeders and validates there is Role attached and Permissions available.
     * @return void
     */
    public function testUserModel()
    {
        (new DatabaseSeeder)->run();
        $user = User::first();
        $this->assertNotNull($user);

        $this->assertTrue($user->hasRole('Admin'));
        $this->assertTrue($user->hasPermissionTo('alarm.create'));
        $this->assertFalse($user->can('test.create'));
        $this->assertTrue($user->can('alarm.create'));
    }
}
