<?php

namespace Tests\Unit;

use App\Models\Course;
use App\Models\School;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ParentStudentRelationshipsTest extends TestCase
{
    use RefreshDatabase;

    protected User $parent1;
    protected User $parent2;

    protected User $student1;
    protected User $student2;

    public function setUp(): void
    {
        parent::setUp();
        $this->setupUsers();
    }

    private function setupUsers()
    {
        (new DatabaseSeeder)->run();
        $school = School::create(['name' => 'School A']);

        $this->student1 = User::factory()->create([
            'name' => 'Student A',
            'email' => 'studentA@gmail.com',
            'school_id' => $school->getKey(),
        ]);
        $this->student1->assignRole(Course::ROLE_STUDENT);

        $this->student2 = User::factory()->create([
            'name' => 'Student B',
            'email' => 'studentB@gmail.com',
            'school_id' => $school->getKey(),
        ]);
        $this->student2->assignRole(Course::ROLE_STUDENT);

        $this->parent1 = User::factory()->create([
            'name' => 'Parent AB',
            'email' => 'parentAB@gmail.com',
            'school_id' => $school->getKey(),
        ]);
        $this->student1->assignRole(Course::ROLE_PARENT);

        $this->parent2 = User::factory()->create([
            'name' => 'Parent B',
            'email' => 'parentB@gmail.com',
            'school_id' => $school->getKey(),
        ]);
        $this->parent2->assignRole(Course::ROLE_PARENT);
    }

    public function testParentRelationship()
    {
        // Student A have two Parent A and Parent B.
        $this->student1->parents()->attach([$this->parent1->getKey(), $this->parent2->getKey()]);
        $this->assertEquals(2, $this->student1->parents->count());

        // Student B have Parent A
        $this->student2->parents()->attach([$this->parent1->getKey()]);
        $this->assertEquals(1, $this->student2->parents->count());


        // Parent A have two child A and B
        $this->assertEquals(2, $this->parent1->students->count());

        $this->assertContains('Student A', $this->parent1->students->pluck('name'));
        $this->assertContains('Student B', $this->parent1->students->pluck('name'));

        // Parent B have one children A.
        $this->assertEquals(1, $this->parent2->students->count());
        $this->assertContains('Student A', $this->parent2->students->pluck('name'));
        $this->assertNotContains('Student B', $this->parent2->students->pluck('name'));
    }
}
