<?php

namespace Tests\Functional\API;

use App\Models\Course;
use App\Models\Role;
use App\Models\School;
use App\Models\User;
use Tests\ApiTestCase;

class RoleTest extends ApiTestCase
{
    /**
     * Validates we will see query parameters in pagination links.
     */
    public function testGetRolesLinks()
    {
        $this->actingAs($this->adminUser)->get('/api/roles?include=users&per_page=2')
            ->assertSeeText('/roles?include=users');
    }

    public function testCreateEmptyRole()
    {
        $this->actingAs($this->adminUser)->postJson(
            '/api/roles?include=permissions,users',
            [
                'data' => [
                    'attributes' => [
                        'name' => Course::ROLE_TEACHER,
                    ]
                ]
            ]
        )->assertJsonFragment(['name' => Course::ROLE_TEACHER]);
    }

    public function testCreateWrongRole()
    {
        $this->actingAs($this->adminUser)->postJson(
            '/api/roles?include=permissions,users',
            [
                'data' => [
                    'attributes' => [
                        'name' => 'Role of Teachers',
                    ]
                ]
            ]
        )->assertJsonFragment(['message' => 'data.attributes.name: The selected data.attributes.name is invalid.']);
    }

    public function testAddUserToGlobalRole()
    {
        $role = Role::whereName("teacher")->first();

        $this->actingAs($this->adminUser)->patchJson(
            '/api/roles/' . $role->getKey() .
            '/users_permissions?include=users',
            [
                'data' => [
                    'attributes' => [
                        // Add User
                        'users' => [
                            ['user_id' => $this->parentUser->getKey()],
                        ],
                        // Add Permission
                        'permissions' => [
                            'user.password_reset',
                        ],
                    ]
                ]
            ]
        )->assertJsonFragment(['message' => 'You shall not edit Global Role!']);
        $this->assertCount(1, $this->parentUser->refresh()->roles, 'There should be one parent Role');

        $this->actingAs($this->adminUser)->patchJson(
            '/api/roles/' . $role->getKey() .
            '/users_permissions?include=users',
            [
                'data' => [
                    'attributes' => [
                        // Add User
                        'users' => [
                            ['user_id' => $this->parentUser->getKey()],
                        ],
                    ]
                ]
            ]
        )->assertJsonFragment(['name' => $this->parentUser->name]);
    }

//    public function testGetTeacherRole()
//    {
//        $this->actingAs($this->teacherUser1);
//        $this->enableSqlDebug();
////        dd(\DB::table("model_has_roles")->get());
//
//        setPermissionsTeamId($this->teacherUser1->school_id);
//        dd($this->teacherUser1->refresh()->roles);
//    }

    public function testRemoveUserFromGlobalRole()
    {
        $role = Role::whereName("parent")->first();

        $this->actingAs($this->adminUser)->patchJson(
            '/api/roles/' . $role->getKey() .
            '/users_permissions?include=users',
            [
                'data' => [
                    'attributes' => [
                        // Remove User
                        'users' => [
                            ['user_id' => $this->parentUser->getKey()],
                        ],
                        // Try to remove Permission
                        'permissions' => [
                            'user.password_reset',
                        ],
                    ]
                ]
            ]
        )->assertJsonFragment(['message' => 'You shall not edit Global Role!']);
        // Unsuccessful permission edit request should not affect Role edition.
        $this->assertCount(1, $this->parentUser->refresh()->roles, 'There should be one parent Role');

        $this->actingAs($this->adminUser)->deleteJson(
            '/api/roles/' . $role->getKey() .
            '/users_permissions?include=users',
            [
                'data' => [
                    'attributes' => [
                        // Remove User
                        'users' => [
                            ['user_id' => $this->parentUser->getKey()],
                        ],
                    ]
                ]
            ]
        )->assertDontSeeText($this->parentUser->name);
        $this->assertCount(0, $this->parentUser->refresh()->roles, 'There should be no Role');
    }

    public function testCreateRole()
    {
        $this->actingAs($this->adminUser)->postJson(
            '/api/roles?include=permissions,users',
            [
                'data' => [
                    'attributes' => [
                        'name' => Course::ROLE_TEACHER,
                        'description' => "Role of Teachers",
                        'users' => [
                            ['user_id' => $this->teacherUser1->getKey()],
                            ['user_id' => $this->teacherUser2->getKey()],
                        ],
                        'permissions' => [
                            'group.view',
                            'group.create',
                        ],
                    ]
                ]
            ]
        )->assertJsonFragment(['description' => 'Role of Teachers'])
            ->assertJsonFragment(["name" => Course::ROLE_TEACHER])
            // Teachers should be added.
            ->assertJsonFragment(["name" => "group.view"])
            ->assertJsonFragment(["name" => "group.create"]);

        $createdRole = Role::whereDescription("Role of Teachers")->first();
        // Ensure there is a proper school_id attached.
        $this->assertEquals($this->adminUser->school_id, $createdRole->school_id);

        // DELETE /api/roles/{role_id/users_permissions (Remove Users, Permissions)
        $this->actingAs($this->adminUser)->deleteJson(
            '/api/roles/' . $createdRole->getKey() .
            '/users_permissions?include=permissions,users',
            [
                'data' => [
                    'attributes' => [
                        // Remove User
                        'users' => [
                            ['user_id' => $this->teacherUser2->getKey()],
                        ],
                        // Remove Permission
                        'permissions' => [
                            'group.create',
                        ],
                    ]
                ]
            ]
        )->assertDontSeeText("group.create")
            ->assertDontSeeText($this->teacherUser2->name);

        // PATCH /api/roles/{role_id/users_permissions (Add Users, Permissions)
        $this->actingAs($this->adminUser)->patchJson(
            '/api/roles/' . Role::whereDescription("Role of Teachers")->first()->getKey() .
            '/users_permissions?include=permissions,users',
            [
                'data' => [
                    'attributes' => [
                        // Add User
                        'users' => [
                            ['user_id' => $this->teacherUser2->getKey()],
                        ],
                        // Add Permission
                        'permissions' => [
                            'group.create',
                        ],
                    ]
                ]
            ]
        )->assertJsonFragment(['name' => 'group.create'])
            ->assertJsonFragment(['name' => $this->teacherUser2->name]);
    }

    /**
     * Retrieve Role list via API call.
     *
     * @return void
     * @throws \Throwable
     */
    public function testGetRoles()
    {
        /** @var User $schoolBAdmin */
        $schoolBAdmin = User::factory(['name' => 'Admin B', 'school_id' => School::factory(['name' => 'School B'])])
            ->create();
        $schoolBAdmin->assignRole('admin');

        $this->actingAs($this->adminUser)->get('/api/roles?include=users')
            ->assertJsonFragment(['name' => 'admin'])
            ->assertJsonFragment(['name' => 'student'])
            // There are Users included.
            ->assertJsonFragment(['type' => 'user'])
            // Admin B should be seen only from Admin B User.
            ->assertDontSeeText('Admin B');

        $this->actingAs($schoolBAdmin)->get('/api/roles?include=users')
            ->assertJsonFragment(['name' => 'admin'])
            ->assertJsonFragment(['name' => 'student'])
            // There are Users included.
            ->assertJsonFragment(['type' => 'user'])
            // Admin B should be seen only from Admin B User.
            ->assertJsonFragment(['name' => 'Admin B'])
            // And no users from School A.
            ->assertDontSeeText('Teacher A');
    }

    public function testGetRole()
    {
        // Custom Role for other school.
        $roleB = Role::create(['name'=>'test1', 'school_id' => School::create(['name' => 'School B'])->getKey()]);

        $this->actingAs($this->adminUser)
            ->get('/api/roles/' . Role::findByName(Course::ROLE_PARENT)->getKey())
            ->assertJsonFragment(['name' => 'parent']);

        $this->actingAs($this->adminUser)
            ->get('/api/roles/' . $roleB->getKey())
            // School A should not see School B Roles.
            ->assertDontSeeText('test1');
    }

    public function testDeleteRole()
    {
        $role = Role::findByName(Course::ROLE_PARENT);

        // Initially we wouldn't be able to delete the Role, since there are some users attached to the Role.
        $this->actingAs($this->adminUser)
            ->deleteJson('/api/roles/' . $role->getKey())
            ->assertJsonFragment(['message' => 'Can not delete non-empty Role'])
            ->assertJsonFragment(['title' => 'There are 2 User(s) attached to the Role!']);

        $this->parentUser->removeRole($role);
        $this->parentUser2->removeRole($role);

        // Now Role could be deleted.
        $this->actingAs($this->adminUser)
            ->deleteJson('/api/roles/' . $role->getKey())
            ->assertSuccessful();
    }

    public function testUpdateCustomRole()
    {
        /** @var Role $role */
        $role = Role::create(['name' => "Custom Parent Role", 'description' => 'TODO Actor']);
        // School A should be attached to the Role.
        $this->assertEquals($this->adminUser->school->name, $role->school->name);

        $response = $this->actingAs($this->adminUser)->patchJson(
            '/api/roles/' . $role->getKey(),
            ['data' => ['attributes' => ['description' => 'New Role Name', 'school_id' => 666]]]
        );
        $response->assertJsonFragment(['description' => 'New Role Name']);

        // Ensure there is still the proper school_id attached.
        $this->assertEquals($this->adminUser->school_id, $role->school_id);
    }

    public function testUpdateGlobalRole()
    {
        /** @var Role $role */
        $role = Role::findByName(Course::ROLE_PARENT);

        $response = $this->actingAs($this->adminUser)->patchJson(
            '/api/roles/' . $role->getKey(),
            ['data' => ['attributes' => ['description' => 'New Role Name', 'school_id' => 666]]]
        );
        $response->assertJsonFragment(['message' => 'any_field: You shall not update global Role!']);

        // Ensure there is still the proper school_id === null attached (global Role).
        $this->assertEquals(null, $role->school_id);
    }

    public function testGetRolesSchoolB()
    {
        /** @var User $schoolBAdmin */
        $schoolBAdmin = User::factory(['name' => 'Admin B', 'school_id' => School::factory(['name' => 'School B'])])
            ->create();
        $schoolBAdmin->assignRole('admin');

        $this->actingAs($schoolBAdmin)->postJson(
            '/api/roles?include=permissions,users',
            [
                'data' => [
                    'attributes' => [
                        'name' => Course::ROLE_TEACHER,
                        'description' => "Role B",
                        'users' => [
                            ['user_id' => $this->teacherUser1->getKey()],
                            ['user_id' => $this->teacherUser2->getKey()],
                        ],
                        'permissions' => [
                            'group.view',
                            'group.create',
                        ],
                    ]
                ]
            ]
        )->assertJsonFragment(['description' => 'Role B'])
            ->assertJsonFragment(["name" => Course::ROLE_TEACHER])

            // Teachers from School A should NOT be added!
            ->assertDontSeeText($this->teacherUser1->name)
            ->assertDontSeeText($this->teacherUser2->name)
            // Permissions should be seen.
            ->assertJsonFragment(["name" => "group.view"])
            ->assertJsonFragment(["name" => "group.create"]);

        $this->actingAs($schoolBAdmin)->get('/api/roles?include=users')
            ->assertJsonFragment(['description' => 'Role B']);

        $this->actingAs($this->adminUser)->get('/api/roles?include=users')
            ->assertDontSeeText('Role B');
    }
}
