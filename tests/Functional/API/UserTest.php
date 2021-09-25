<?php

namespace Tests\Functional\API;

use App\Http\ApiCodes;
use App\Models\Course;
use App\Models\User;
use Tests\ApiTestCase;

class UserTest extends ApiTestCase
{
    /**
     * Retrieve User information via API call.
     *
     * @return void
     * @throws \Throwable
     */
    public function testGetUsers()
    {
        $response = $this->get('/api/users');

        $response->assertJsonFragment(['email' => $this->user->email]);
        $response->assertJsonFragment(['name' => $this->user->name]);

        /** @var string $id Get first User ID */
        $id = $response->decodeResponseJson()['data'][0]['id'];
        $this->assertIsString($id, 'Should be string UUID');

        $response = $this->get('/api/users/' . $id);
        $response->assertJsonFragment(['email' => $this->user->email]);
        $response->assertJsonFragment(['name' => $this->user->name]);
    }

    /**
     * Test we can retrieve only Students.
     */
    public function testGetStudents()
    {
        $student1 = User::factory()->create([
            'name' => 'Student A',
            'email' => 'studentA@gmail.com',
        ]);
        $student1->assignRole(Course::ROLE_STUDENT);
        $student1->parents()->attach([$this->parentUser->getKey()]);

        $response = $this->get('/api/users/students');

        $response->assertJsonFragment(['email' => $student1->email]);
        $response->assertJsonFragment(['name' => $student1->name]);
        $response->assertDontSeeText(['Teacher']);
    }

    /**
     * Test we can retrieve only Teachers.
     */
    public function testGetTeachers()
    {
        $response = $this->get('/api/users/teachers');

        $response->assertJsonFragment(['email' => $this->teacherUser1->email]);
        $response->assertJsonFragment(['name' => $this->teacherUser1->name]);
        $response->assertDontSeeText(['Admin']);
    }

    /**
     * Retrieve logged in User information via API call.
     *
     * @return void
     */
    public function testGetMyUser()
    {
        $response = $this->get('/api/users/myself');

        $response->assertJsonFragment(['email' => $this->user->email]);
        $response->assertJsonFragment(['name' => $this->user->name]);
        $response->assertDontSee('permissions');
    }

    public function testUpdateUser()
    {
        $response = $this->patchJson(
            '/api/users/' . $this->user->id,
            ['data' => ['attributes' => ['name' => 'New Name']]]
        );

        $response->assertJsonFragment(['email' => $this->user->email]);
        $response->assertJsonFragment(['name' => 'New Name']);
        $response->assertDontSee($this->user->name);

        // Try to update other user
        $user2 = User::factory()->create();
        $this->patchJson(
            '/api/users/' . $user2->id,
            ['data' => ['attributes' => ['name' => 'New Name']]]
        )->assertJsonFragment(['message' => 'Unauthorized'])
            ->assertJsonFragment(['title' => 'Your request is unauthorized'])
            ->assertJsonFragment(['code' => ApiCodes::UNAUTHORIZED]);
    }

    /**
     * Retrieve logged in User information along with Permissions.
     *
     * @return void
     */
    public function testGetMyUserPermissions()
    {
        $response = $this->get('/api/users/myself?include=permissions');

        $response->assertJsonFragment(['name' => 'alarm.create']);
        $response->assertJsonFragment(['name' => 'alarm.delete']);
        $response->assertSee('permissions');
    }

    public function testGetMyUserRoles()
    {
        $response = $this->get('/api/users/myself?include=roles');

        $response->assertJsonFragment(['name' => 'Admin']);
        $response->assertSee('roles');
    }

    /**
     * This will validate User show policy.
     */
    public function testGetWrongUser()
    {
        $user = User::factory()->create();

        $response = $this->get("/api/users/{$user->id}");

        $response->assertJsonFragment(['message' => 'Unauthorized']);
        $response->assertJsonFragment(['code' => ApiCodes::UNAUTHORIZED]);
    }
}
