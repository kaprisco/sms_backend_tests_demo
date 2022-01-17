<?php

namespace Tests\Unit;

use App\Models\Course;
use App\Models\School;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserModelTest extends TestCase
{
    use RefreshDatabase;

    public function setUp(): void
    {
        parent::setUp();
        (new DatabaseSeeder)->run();
    }

    /**
     * Run Database Seeders and validates there is Role attached and Permissions available.
     * @return void
     */
    public function testUserModel()
    {
        $user = User::whereEmail('admin@demo.com')->first();
        $this->assertNotNull($user);

        $this->assertTrue($user->hasRole(Course::ROLE_ADMIN));
        $this->assertTrue($user->hasPermissionTo('alarm.create'));
        $this->assertFalse($user->can('test.create'));
        $this->assertTrue($user->can('alarm.create'));
    }

    public function testUserRoleFactory()
    {
        $school = School::factory()->create();
        /** @var User $user */
        $user = User::factory(['school_id' => $school->getKey()])->asParent()->create();
        setPermissionsTeamId($user->school_id);
        $this->assertTrue($user->refresh()->hasRole(Course::ROLE_PARENT));

        $user = User::factory(['school_id' => $school->getKey()])->asTeacher()->create();
        $this->assertTrue($user->refresh()->hasRole(Course::ROLE_TEACHER));

        $user = User::factory(['school_id' => $school->getKey()])->asStudent()->create();
        $this->assertTrue($user->refresh()->hasRole(Course::ROLE_STUDENT));
    }
}
