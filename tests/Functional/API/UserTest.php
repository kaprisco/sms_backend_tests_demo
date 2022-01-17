<?php

namespace Tests\Functional\API;

use App\Http\ApiCodes;
use App\Models\Course;
use App\Models\Group;
use App\Models\School;
use App\Models\User;
use Illuminate\Support\Facades\Password;
use Tests\ApiTestCase;

class UserTest extends ApiTestCase
{
    /**
     * Validates we will see query parameters in pagination links.
     */
    public function testGetUsersLinks()
    {
        $this->get('/api/users?include=permissions&per_page=2')
            ->assertSeeText('/users?include=permissions');
    }

    public function testResetPasswordForAlien()
    {
        $alienUser = User::factory(['school_id' => School::create(['name' => 'School B'])->getKey()])->create();
        $this->actingAs($this->adminUser)->get('/api/users/' . $alienUser->getKey() . '/password_reset')
            // this would not be able to find User from other School.
            ->assertSeeText('User not found');
    }

    public function testResetPassword()
    {
        $this->actingAs($this->teacherUser1)->get('/api/users/' . $this->teacherUser1->getKey() . '/password_reset')
            ->assertJsonFragment(['message' => 'Unauthorized']);
        $this->actingAs($this->teacherUser1)->get('/api/users/myself/password_reset')
            ->assertSeeText(Password::RESET_LINK_SENT);

        // Here we validate admin could request password reset for other User.
        $this->actingAs($this->adminUser)->get('/api/users/' . $this->teacherUser1->getKey() . '/password_reset')
        // We already just send reset email, so the next one to the same user would be throttled.
            ->assertSeeText(Password::RESET_THROTTLED);
    }

    public function testCreateUser()
    {
        $this->actingAs($this->adminUser)->postJson(
            '/api/users?include=roles',
            [
                'data' => [
                    'attributes' => [
                        'email' => 'user1@test.com',
                        'name' => "First",
                        'last_name' => "Last",
                        'phone' => '123456789',
                        'address' => 'address information',
                        'city' => 'City',
                        'state' => 'ZZ',
                        'zipcode' => '123456',
                        'country' => 'AU',
                        'role' => Course::ROLE_STUDENT,
                    ]
                ]
            ]
        )->assertJsonFragment(['name' => Course::ROLE_STUDENT])
            ->assertJsonFragment(['email' => 'user1@test.com'])
            ->assertJsonFragment(['phone' => '123456789'])
            ->assertJsonFragment(['address' => 'address information'])
            ->assertJsonFragment(['city' => 'City'])
            ->assertJsonFragment(['state' => 'ZZ'])
            ->assertJsonFragment(['zipcode' => '123456'])
            ->assertJsonFragment(['country' => 'AU']);

        $this->actingAs($this->parentUser)->postJson(
            '/api/users?include=roles',
            [
                'data' => [
                    'attributes' => [
                        'email' => 'user1@test.com',
                        'name' => "First",
                        'last_name' => "Last",
            //                        'name' => "First Last",
                    ]
                ]
            ]
        )->assertJsonFragment(['message' => 'Unauthorized']);
    }

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

        $response = $this->get('/api/users/' . $this->user->getKey());
        $response->assertJsonFragment(['email' => $this->user->email]);
        $response->assertJsonFragment(['name' => $this->user->name]);
    }

    public function testGetUsersForGroup()
    {
        $group = Group::factory()->create(['name' => 'Group A']);
        $this->teacherUser1->assignGroup($group);
        $response = $this->get('/api/users/?group_id=' . $group->getKey());
        $response->assertJsonFragment(['total' => 1])
            ->assertJsonFragment(['name' => $this->teacherUser1->name]);
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

        // These are identical results, but using generic Users API list with the filter.
        $response = $this->get('api/users?role=' . Course::ROLE_STUDENT);
        $response->assertJsonFragment(['email' => $student1->email]);
        $response->assertJsonFragment(['name' => $student1->name]);
        $response->assertDontSeeText(['Teacher']);
    }

    public function testUserSearch()
    {
        // Name search
        $this->get('/api/users/?q=parent')
            ->assertJsonFragment(['total' => 2])
            ->assertJsonFragment(['name' => 'Parent A'])
            ->assertJsonFragment(['name' => 'Parent B']);

        // Email search
        $this->get('/api/users/?q=parentA')
            ->assertJsonFragment(['total' => 1])
            ->assertJsonFragment(['email' => 'parentA@gmail.com']);

        // Filter should work for Teachers list
        $this->get('/api/users/teachers?q=Teacher A')
            ->assertJsonFragment(['total' => 1])
            ->assertJsonFragment(['name' => 'Teacher A']);

        User::factory()->asStudent()->create([
            'name' => 'Student A',
            'email' => 'studentA@gmail.com',
        ]);
        User::factory()->asStudent()->create([
            'name' => 'Student C',
            'email' => 'studentB@gmail.com',
        ]);

        // Filter should work for Students list.
        $this->get('/api/users/students?q=Student A')
            ->assertJsonFragment(['total' => 1])
            ->assertJsonFragment(['name' => 'Student A']);
    }

    public function testGetStudentParents()
    {
        $student1 = User::factory()->asStudent()->create([
            'name' => 'Student A',
            'email' => 'studentA@gmail.com',
        ]);

        $student2 = User::factory()->asStudent()->create([
            'name' => 'Student C',
            'email' => 'studentB@gmail.com',
        ]);
        $student1->parents()->attach([$this->parentUser->getKey(), $this->parentUser2->getKey()]);

        $this->get('/api/users/' . $student1->getKey())
            ->assertJsonFragment(['message' => 'Unauthorized'])
            ->assertJsonFragment(['code' => ApiCodes::UNAUTHORIZED]);

        // Teacher can view the Student and the Parents.
        $this->actingAs($this->teacherUser1);
        $this->get('/api/users/students?include=parents&has_parents=1')
            ->assertJsonFragment(['name' => 'Parent A'])
            ->assertJsonFragment(['name' => 'Parent B'])
//            // Whenever we include parents we don't want students here w/o a Parent.
            ->assertDontSeeText('Student C');

        $this->get('/api/users/students?include=parents')
            ->assertJsonFragment(['name' => 'Parent A'])
            ->assertJsonFragment(['name' => 'Parent B'])
            ->assertJsonFragment(['name' => 'Student C']);
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

        // These are identical results, but using generic Users API list with the filter.
        $response = $this->get('api/users?role=' . Course::ROLE_TEACHER);
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

    public function testDeleteUser()
    {
        $this->actingAs($this->adminUser)
            ->deleteJson('/api/users/' . $this->teacherUser1->getKey())->assertSuccessful();
    }

    public function testUpdateUser()
    {
        $response = $this->actingAs($this->teacherUser1)->patchJson(
            '/api/users/' . $this->teacherUser1->getKey(),
            ['data' => ['attributes' => ['name' => 'New Name', 'profile_photo_url' => 'https://test.com/ava.gif']]]
        );

        $response->assertJsonFragment(['email' => $this->teacherUser1->email]);
        $response->assertJsonFragment(['name' => 'New Name']);
        $response->assertDontSee($this->user->name);

        // Try to update other user
        $this->actingAs($this->teacherUser1)->patchJson(
            '/api/users/' . $this->teacherUser2->getKey(),
            ['data' => ['attributes' => ['name' => 'New Name']]]
        )->assertJsonFragment(['message' => 'Unauthorized'])
            ->assertJsonFragment(['title' => 'Your request is unauthorized'])
            ->assertJsonFragment(['code' => ApiCodes::UNAUTHORIZED]);

        // Admin should be able to update other user.
        $this->actingAs($this->adminUser)->patchJson(
            '/api/users/' . $this->teacherUser2->getKey(),
            ['data' => ['attributes' => ['name' => 'New Name']]]
        )->assertJsonFragment(['name' => 'New Name']);
    }

    /**
     * Retrieve logged in User information along with Roles, Permissions, and Groups.
     *
     * @return void
     */
    public function testGetMyUserIncludes()
    {
        $this->user->assignGroup(Group::factory()->create(['name' => 'Group A']));
        $response = $this->get('/api/users/myself?include=permissions,roles,groups');

        $response->assertJsonFragment(['name' => 'alarm.create']);
        $response->assertJsonFragment(['name' => 'alarm.delete']);
        $response->assertSee('permissions');

        $response->assertJsonFragment(['name' => 'Tester']);
        $response->assertSee('roles');

        $response->assertJsonFragment(['name' => 'Group A']);
        $response->assertSee('groups');
    }

    public function testGetMyUserChildren()
    {
        $student1 = User::factory(['name' => 'Student A'])->asStudent()->create();
        $student2 = User::factory(['name' => 'Student B'])->asStudent()->create();

        $this->parentUser->students()->attach([$student1->getKey(), $student2->getKey()]);

        $this->actingAs($this->parentUser)->get('/api/users/myself?include=students')
            ->assertJsonFragment(['name' => 'Student A'])
            ->assertJsonFragment(['name' => 'Student B']);
    }

    /**
     * This will validate User show policy.
     */
    public function testGetWrongUser()
    {
        $this->get("/api/users/" . User::factory()->create()->getKey())
            ->assertJsonFragment(['message' => 'Unauthorized'])
            ->assertJsonFragment(['code' => ApiCodes::UNAUTHORIZED]);
    }
}
