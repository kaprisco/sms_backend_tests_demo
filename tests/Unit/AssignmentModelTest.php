<?php

namespace Tests\Unit;

use App\Models\Assignment;
use App\Models\Course;
use App\Models\Rubric;
use App\Models\School;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AssignmentModelTest extends TestCase
{
    use RefreshDatabase;

    public function testAssignmentRelations()
    {
        $course = Course::factory(['name' => 'Chemistry'])->create();

        /** @var Assignment $assignment */
        $assignment = Assignment::factory([
            'course_id' => $course->getKey(),
            'rubric_id' => Rubric::factory([
                'name' => 'Rubric A',
                'school_id' => School::factory()->create(),
            ])->create(),
        ])->count(2)->create();

        $this->assertEquals('Chemistry', $assignment[0]->course->name);
        $this->assertEquals('Chemistry', $assignment[1]->course->name);

        // Test inverse of relation.
        $this->assertCount(2, $course->assignments);

        // Test Rubric relationship.
        $this->assertEquals('Rubric A', $assignment[1]->rubric->name);
    }
}
