<?php

namespace Tests\Functional\API;

use App\Http\Controllers\API\Transformers\AssignmentTransformer;
use App\Models\Assignment;
use App\Models\Course;
use App\Models\Role;
use App\Models\Rubric;
use App\Models\School;

class AssignmentRubricTest extends AssignmentTest
{
    public function testCreateAssignmentWithRubric()
    {
        $payload = [
            'data' => [
                'attributes' => [
                    'publish_at' => now()->subDay(),
                    'deadline_at' => now()->addDay(),
                    'scoring_config' => [],
                    'name' => 'Assignment 1',
                    'rubric' => [
                        'name' => 'Rubric A',
                        'description' => 'Rubric description',
                        'data' => [
                            'att1' => '1',
                            'att2' => '2',
                        ]
                    ],
                ]
            ]
        ];
        $this->actingAs($this->teacherUser1)->postJson(
            '/api/courses/' . $this->course->getKey() . '/assignments?include=rubric',
            $payload,
        )->assertJsonFragment(['type' => AssignmentTransformer::JSON_OBJ_TYPE])
            ->assertJsonFragment(['name' => 'Assignment 1'])
            ->assertJsonFragment(['name' => 'Rubric A'])
            ->assertJsonFragment(['att1' => '1'])
            ->assertJsonFragment(['att2' => '2']);

        $role = Role::whereName(Course::ROLE_TEACHER)->first();
        $role->revokePermissionTo(['assignment.create']);

        // Check that assignment permission is validated.
        $this->actingAs($this->teacherUser1)->postJson(
            '/api/courses/' . $this->course->getKey() . '/assignments?include=rubric',
            $payload,
        )->assertJsonFragment(['message' => 'Unauthorized']);
    }

    public function testCreateAssignmentWithReusableRubric()
    {
        /** @var Rubric $rubricA */
        $rubricA = Rubric::factory(['name' => 'Rubric A'])->create();
        $rubricB = Rubric::factory(['name' => 'Rubric B', 'school_id' => School::factory()->create()])->create();

        $payload = [
            'data' => [
                'attributes' => [
                    'publish_at' => now()->subDay(),
                    'deadline_at' => now()->addDay(),
                    'scoring_config' => [],
                    'name' => 'Assignment 1',
                    'rubric_id' => $rubricA->getKey(),
                ]
            ]
        ];

        $this->assertEquals($this->teacherUser1->school_id, $rubricA->school_id);
        // Rubric B belong to other School.
        $this->assertNotEquals($this->teacherUser1->school_id, $rubricB->school_id);

        $this->markTestSkipped('School id is not implemented in Course and this is not functional yet!');
        // We should not allow reusing Rubric from other school.
        $this->actingAs($this->teacherUser1)->postJson(
            '/api/courses/' . $this->course->getKey() . '/assignments?include=rubric',
            $payload,
        )->assertJsonFragment(['detail' => 'Rubric is not matched!']);
    }

    /**
     * Test the Teacher can update the Rubric.
     */
    public function testAssignmentRubricUpdate()
    {
        $assignment = Assignment::factory(['course_id' => $this->course->getKey()])->create();

        /** @var Rubric $rubricA */
        $rubricA = Rubric::factory(['name' => 'Rubric A'])->create();

        $this->actingAs($this->teacherUser1)->patchJson(
            $this->url . '/' . $assignment->getKey() . '?include=rubric',
            [
                'data' => [
                    'attributes' => [
                        'rubric_id' => $rubricA->getKey(),
                    ]
                ]
            ]
        )->assertJsonFragment(['name' => 'Rubric A']);
    }
}
