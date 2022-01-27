<?php

namespace Tests\Functional\API;

use App\Http\ApiCodes;
use App\Models\Course;
use App\Models\Group;
use App\Models\School;
use App\Models\Term;
use App\Models\User;
use Tests\ApiTestCase;

class GroupTest extends ApiTestCase
{
    private Group $groupA;
    private Group $groupB;

    private static array $customData = [
        'logo' => 'somelogo.svg',
        'color' => 'black',
    ];

    public function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->teacherUser1);
        $this->groupA = Group::factory()->for(Term::factory())->hasAttached([$this->parentUser, $this->parentUser2])
            ->create(['name'=>'Group Parents']);
        $this->assertEquals($this->groupA->owner_id, $this->teacherUser1->getKey());

        $this->actingAs($this->user);

        $this->groupB = Group::factory()->for(Term::factory())->hasAttached([$this->teacherUser1, $this->teacherUser2])
            ->create(['name'=>'Group Teachers']);
        $this->assertEquals($this->groupB->owner_id, $this->user->getKey());
    }

    /**
     * Validates we will see query parameters in pagination links.
     */
    public function testGetGroupsLinks()
    {
        $this->get('/api/groups?include=users&per_page=2')
            ->assertSeeText('/groups?include=users');
    }

    public function testCreateGroup()
    {
        $this->actingAs($this->adminUser)->postJson(
            '/api/groups?include=term,users',
            [
                'data' => [
                    'attributes' => [
                        'name' => "Group of Teachers",
                        'role_filter' => Course::ROLE_TEACHER,
                        'term_id' => Term::factory()->create(['name' => 'Term 2022'])->getKey(),
                        'users' => [
                            ['user_id' => $this->teacherUser1->getKey()],
                            ['user_id' => $this->teacherUser2->getKey()],
                            // This user would be filtered out.
                            ['user_id' => $this->parentUser->getKey()],
                        ],
                        'data' => self::$customData,
                    ]
                ]
            ]
        )->assertJsonFragment(['name' => 'Group of Teachers'])
            ->assertJsonFragment(['role_filter' => Course::ROLE_TEACHER])
            ->assertJsonFragment(['name' => 'Term 2022'])
            // Teachers should be added.
            ->assertJsonFragment(["name" => "Teacher A"])
            ->assertJsonFragment(["name" => "Teacher B"])
            ->assertJsonFragment(["data" => self::$customData])
        ->assertDontSeeText("Parent A");

        // Try to rename the group with other User.
        $this->actingAs($this->parentUser)->postJson(
            '/api/groups?include=roles',
            ['data' => ['attributes' => ['name' => "Group A",]]]
        )->assertJsonFragment(['message' => 'Unauthorized']);
    }

    public function testUnauthorizedAddRemove()
    {
        // Add Users to the Group.
        $this->actingAs($this->parentUser)->postJson(
            "/api/groups/{$this->groupA->id}/add_users?include=users",
            [
                'users' => [
                    ['user_id' => $this->teacherUser1->getKey()],
                    ['user_id' => $this->teacherUser2->getKey()],
                ]
            ]
        )->assertJsonFragment(['message' => 'Unauthorized']);

        // Remove Users from the Group.
        $this->actingAs($this->parentUser)->postJson(
            "/api/groups/{$this->groupA->id}/delete_users?include=users",
            [
                'users' => [
                    ['user_id' => $this->teacherUser1->getKey()],
                    ['user_id' => $this->teacherUser2->getKey()],
                ]
            ]
        )->assertJsonFragment(['message' => 'Unauthorized']);
    }

    public function testAddRemoveUsers()
    {
        $alienUser = User::factory()->create(['name' => 'Alien', 'school_id' => School::factory()]);

        // Add Users to the Group.
        $this->actingAs($this->teacherUser1)->postJson(
            "/api/groups/{$this->groupA->id}/add_users?include=users,term",
            [
                'users' => [
                    ['user_id' => $this->teacherUser1->getKey()],
                    ['user_id' => $this->teacherUser2->getKey()],
                    // This should be ignored.
                    ['user_id' => 'random'],
                    ['user_id' => $alienUser->getKey()],
                ]
            ]
        )->assertJsonFragment(["name" => "Parent A"])
            ->assertJsonFragment(["name" => "Parent B"])
            ->assertJsonFragment(["name" => "Teacher A"])
            ->assertJsonFragment(["name" => "Teacher B"])
            ->assertDontSeeText("Alien");

        // Remove Users from the Group.
        $this->actingAs($this->teacherUser1)->postJson(
            "/api/groups/{$this->groupA->id}/delete_users?include=users",
            [
                'users' => [
                    ['user_id' => $this->teacherUser1->getKey()],
                    ['user_id' => $this->teacherUser2->getKey()],
                ]
            ]
        )->assertJsonFragment(["name" => "Parent A"])
            ->assertJsonFragment(["name" => "Parent B"])
            ->assertDontSeeText("Teacher A")
            ->assertDontSeeText("Teacher B");
    }

    /**
     * Retrieve User information via API call.
     *
     * @return void
     * @throws \Throwable
     */
    public function testGetGroups()
    {
        $response = $this->get('/api/groups?include=users')
            ->assertJsonFragment(['name' => 'Group Teachers'])
            ->assertJsonFragment(['name' => 'Group Parents'])
            // Included Users...
            ->assertJsonFragment(['name' => 'Teacher A'])
            // And User count.
            ->assertJsonFragment(['user_count' => 2]);

        /** @var string $id Get first Group ID */
        $id = $response->decodeResponseJson()['data'][0]['id'];
        $this->assertIsString($id, 'Should be string UUID');

        // Just a user cannot retrieve the Group
        $this->actingAs($this->parentUser)->get('/api/groups/' . $id)
            ->assertJsonFragment(['message' => 'Unauthorized']);

        // Admin can retrieve it.
        $this->actingAs($this->adminUser)->get('/api/groups/' . $id)
            ->assertSuccessful()
            ->assertJsonFragment(['name' => Group::find($id)->name]);

        // Owner of the Group can retrieve it.
        $this->actingAs(Group::find($id)->owner)->get('/api/groups/' . $id)
            ->assertSuccessful()
            ->assertJsonFragment(['name' => Group::find($id)->name]);
    }

    public function testGroupSearch()
    {
        // Name search
        $this->get('/api/groups/?q=parent')
            ->assertJsonFragment(['total' => 1])
            ->assertJsonFragment(['name' => 'Group Parents']);

        // Email search
        $this->get('/api/groups/?q=Teachers')
            ->assertJsonFragment(['total' => 1])
            ->assertJsonFragment(['name' => 'Group Teachers']);
    }

    public function testDeleteGroup()
    {
        $this->actingAs($this->teacherUser1)
            ->deleteJson('/api/groups/' . $this->groupA->getKey())->assertSuccessful();
    }

    public function testUpdateGroup()
    {
        $response = $this->actingAs($this->teacherUser1)->patchJson(
            '/api/groups/' . $this->groupA->getKey(),
            ['data' => ['attributes' => ['name' => 'New Name']]]
        );

        $response->assertJsonFragment(['name' => 'New Name']);

//        $this->teacherUser1->roles->first()->revokePermissionTo('group.update');
        // Try to update other Group
        $this->actingAs($this->teacherUser1)->patchJson(
            '/api/groups/' . $this->groupB->getKey(),
            ['data' => ['attributes' => ['name' => 'New Name']]]
        )->assertJsonFragment(['message' => 'Unauthorized'])
            ->assertJsonFragment(['title' => 'Your request is unauthorized'])
            ->assertJsonFragment(['code' => ApiCodes::UNAUTHORIZED]);
    }
}
