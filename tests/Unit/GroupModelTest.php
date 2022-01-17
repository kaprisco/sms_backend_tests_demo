<?php

namespace Tests\Unit;

use App\Models\Course;
use App\Models\Group;
use App\Models\Term;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\ApiTestCase;
use Tests\TestCase;

class GroupModelTest extends ApiTestCase
{
    use RefreshDatabase;

    public function testUserGroupModel()
    {
        $group1 = new Group();
        $group1->name = "Group A";
        $group1->save();
        $group2 = new Group();
        $group2->name = "Group B";
        $group2->save();

        $this->user->assignGroup([$group1, $group2->getKey()]);
        $this->assertCount(2, $this->user->groups);
        // Check reverse relations.
        $this->assertCount(1, $group1->users);
        $this->assertCount(1, $group2->users);

        $this->user->removeGroup($group2);
        $this->assertCount(1, $this->user->groups);
        $this->assertCount(1, $group1->users);
        $this->assertCount(0, $group2->refresh()->users);

        $this->assertTrue($this->user->hasGroup($group1));
        $this->assertFalse($this->user->hasGroup($group2));
    }

    /**
     * @return void
     */
    public function testGroupModel()
    {
        /** @var Group $groupA */
        $groupA = Group::factory()->for(Term::factory())
            ->hasUsers(2, ['school_id' => $this->user->school_id])
            ->create(['name' => 'Group A', 'owner_id' => $this->user->getKey(), 'school_id' => $this->user]);
        $this->assertNotNull($groupA);
        $this->assertNotNull($groupA->term_id);

        $groupA->users()->sync($this->teacherUser1->getKey(), false);
        $groupA->users()->sync($this->teacherUser2->getKey(), false);

        $groupA->users->pluck('school_id')->each(fn($item) => $this->assertEquals($item, $this->user->school_id));
        $this->assertCount(4, $groupA->users);

        /** @var Group $groupB */
        $groupB = Group::factory()->for(Term::factory())->hasAttached([$this->teacherUser1, $this->teacherUser2])
            ->create(['name'=>'Group B', 'owner_id' => $this->user->getKey(), 'school_id' => $this->user->school_id]);
        $this->assertCount(2, $groupB->users);
        $groupA->users->pluck('school_id')->each(fn($item) => $this->assertEquals($item, $this->user->school_id));

        // Teachers belong to two groups.
        $this->assertCount(2, $this->teacherUser1->groups);
        $this->assertCount(2, $this->teacherUser2->groups);

        // User from GroupA belongs to one Group only.
        $this->assertCount(1, $groupA->users->first()->groups);

        // Test Owner
        // Whoever created the Group should own it.
        $this->assertEquals($this->user->getKey(), $groupA->owner->getKey());
        $this->assertEquals($this->user->getKey(), $groupB->owner->getKey());
    }
}
