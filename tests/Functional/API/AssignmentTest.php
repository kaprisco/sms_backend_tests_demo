<?php

namespace Tests\Functional\API;

use App\Http\ApiCodes;
use App\Http\Controllers\API\Transformers\AssignmentTransformer;
use App\Http\Controllers\API\Transformers\CourseTransformer;
use App\Models\Assignment;
use App\Models\Calendar;
use App\Models\Course;
use App\Models\Group;
use App\Models\Term;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;
use RRule\RRule;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Tests\ApiTestCase;

class AssignmentTest extends ApiTestCase
{
    protected Course $course;

    protected User $student;
    protected User $student2;

    protected string $url;

    public function setUp(): void
    {
        parent::setUp();
        // Disable auditing from this point on
//        Course::disableAuditing();
        Course::withTrashed()->get()->each(function (Course $item) {
            $item->forceDelete();
        });

        $this->student = User::factory(['name' => 'Student B'])->create()->assignRole(Course::ROLE_STUDENT);
        $this->student2 = User::factory(['name' => 'Student C'])->create()->assignRole(Course::ROLE_STUDENT);

        $this->assertTrue($this->teacherUser1->can('course.create'));
        $this->actingAs($this->teacherUser1);

        $this->actingAs($this->teacherUser1)->postJson('/api/courses', [
            'data' => [
                'attributes' => [
                    'term_id' => Term::factory([
                        'start_at' => Carbon::create(2030)->startOfYear(),
                        'end_at' => Carbon::create(2030)->startOfYear()->endOfMonth(),
                    ])->create()->getKey(),
                    'name' => 'Chemistry',
                    // pass users array of students to be added
                    'users' => [
                        ['user_id' => $this->student->getKey(), 'course_role' => Course::ROLE_STUDENT],
                        ['user_id' => $this->student2->getKey(), 'course_role' => Course::ROLE_STUDENT],
//                        ['user_id' => $this->teacherUser2->getKey(), 'course_role' => Course::ROLE_TEACHER],
                    ]
                ]
            ]
        ]);

        $this->course = Course::firstOrFail();
        $this->url = '/api/courses/' . $this->course->getKey() . '/assignments';
    }

    private static array $createPayload = [
        'data' => [
            'attributes' => [
                'publish_at' => '2021-01-01 00:00:00',
                'deadline_at' => '2031-01-01 00:00:00',
                'scoring_config' => [],
                'name' => 'Assignment 1',
            ]
        ]
    ];

    /**
     * Assignment wouldn't be created without a name.
     */
    public function testCreateAssignmentNoName()
    {
        $this->post($this->url)
            ->assertJsonFragment(['title' => 'Failed model validation'])
            ->assertJsonFragment(['message' => 'data.attributes.name: The data.attributes.name field is required.'])
            ->assertJsonFragment(['code' => ApiCodes::MODEL_VALIDATION_ERROR]);
    }

    public function testCreateNoPermissions()
    {
        // Student don't have appropriate role to create Assignment.
        $this->actingAs($this->student)->postJson(
            $this->url,
            self::$createPayload
        )->assertJsonFragment(['message' => 'Unauthorized']);

        // Teacher2 is not the Teacher for Course.
        $this->actingAs($this->teacherUser2)->postJson(
            $this->url,
            self::$createPayload
        )->assertJsonFragment(['message' => 'Unauthorized']);
    }

    /**
     * Creates a new Assignment
     * @throws \Throwable
     */
    public function testCreateAssignment()
    {
        $response = $this->actingAs($this->teacherUser1)->postJson(
            '/api/courses/' . $this->course->getKey() . '/assignments',
            [
                'data' => [
                    'attributes' => [
                        'publish_at' => now()->subDay(),
                        'deadline_at' => now()->addDay(),
                        'scoring_config' => [],
                        'name' => 'Assignment 1',
                    ]
                ]
            ]
        )->assertJsonFragment(['type' => AssignmentTransformer::JSON_OBJ_TYPE])
            ->assertJsonFragment(['name' => 'Assignment 1']);

        $result = $response->decodeResponseJson()['data']['attributes'];
        $this->assertNotNull('id');
        $this->assertNotNull($result['publish_at']);
        $this->assertNotNull($result['deadline_at']);
    }

    public function testAssignmentUploadDelete()
    {
        $assignment = Assignment::factory(['name' => 'Assignment 1', 'course_id' => $this->course->getKey()])->create();

//        $file1 = new UploadedFile(
//            'tests/Assets/boss-400-400.jpg',
//            'boss-400-400.jpg',
//            'image/png',
//            null,
//            true
//        );

        $this->actingAs($this->teacherUser1)->post(
            $this->url . '/' . $assignment->getKey() . '/files',
            [],
            [],
            [
                'file1' => UploadedFile::fake()->image('avatar.jpg', 400, 400),
                'file2' => UploadedFile::fake()->image('avatar.jpg', 400, 400),
            ]
        )->assertJsonFragment(['count' => 2])
            ->assertJsonFragment(['name' => 'avatar'])
            ->assertJsonFragment(['file_name' => 'avatar.jpg'])
            ->assertJsonFragment(['mime_type' => 'image/jpeg'])
            ->assertJsonFragment(['type' => 'image']);


        // Test DELETE.

        // Second teacher is neither a participant and nor owner.
        $this->actingAs($this->teacherUser2)->delete($this->url . '/' . $assignment->getKey() . '/files/1')
            ->assertJsonFragment(['message' => 'Unauthorized']);

        $this->assertEquals(2, Media::count());

        // Try to delete file via other Course.
        $course2 = $this->course->replicate();
        $course2->name = 'Course 2';
        $course2->save();

        $this->actingAs($this->teacherUser1)->delete('/api/courses/' . $course2->getKey() .
            '/assignments/' . $assignment->getKey() . '/files/1')
            ->assertJsonFragment(['message' => 'Unauthorized']);

        $assignment2 = Assignment::factory()->create();

        // Try to delete file via other Assignment.
        $this->actingAs($this->teacherUser1)->delete($this->url . '/' . $assignment2->getKey() . '/files/1')
            ->assertJsonFragment(['code' => '404']);

        // This would delete Media properly
        $this->actingAs($this->teacherUser1)->delete($this->url . '/' . $assignment->getKey() . '/files/1')
            ->assertSuccessful();

        $this->assertEquals(1, Media::count());

        // test INDEX.
        $this->actingAs($this->teacherUser1)->getJson($this->url . '/' . $assignment->getKey() . '/files/')
            ->assertSuccessful()
            ->assertJsonFragment(['count' => 1])
            ->assertJsonFragment(['name' => 'avatar'])
            ->assertJsonFragment(['file_name' => 'avatar.jpg'])
            ->assertJsonFragment(['mime_type' => 'image/jpeg'])
            ->assertJsonFragment(['type' => 'image']);
    }

    public function testListAssignments()
    {
        Assignment::factory(['name' => 'Assignment 1', 'course_id' => $this->course->getKey()])->create();
        Assignment::factory(['name' => 'Assignment 2', 'course_id' => $this->course->getKey()])->create();

        // Primary Teacher can access.
        $this->actingAs($this->teacherUser1)->get($this->url)
            ->assertJsonFragment(['name' => 'Assignment 1'])
            ->assertJsonFragment(['name' => 'Assignment 2']);

        // Student have Student role for the Course.
        $this->actingAs($this->student)->get($this->url)
            ->assertJsonFragment(['name' => 'Assignment 1'])
            ->assertJsonFragment(['name' => 'Assignment 2']);

        // Other Teacher can not access!
        $this->actingAs($this->teacherUser2)->get($this->url)
            ->assertJsonFragment(['code' => (string)Response::HTTP_UNAUTHORIZED]);
    }

    /**
     * Validate we can get the Assignment with `assignment.view` permission
     */
    public function testGetAssignment()
    {
        $assignment = Assignment::factory(['name' => 'Assignment 1', 'course_id' => $this->course->getKey()])->create();

        // Primary Teacher can access.
        $this->actingAs($this->teacherUser1)->get($this->url . '/' . $assignment->getKey())
            ->assertJsonFragment(['name' => 'Assignment 1']);

        // Student can access, since it have 'assignment.view' permission AND view access for Course.
        $this->actingAs($this->student)->get($this->url . '/' . $assignment->getKey())
            ->assertJsonFragment(['name' => 'Assignment 1']);

        // Parent don't have 'assignment.view' permission
        $this->actingAs($this->parentUser)->get($this->url . '/' . $assignment->getKey())
            ->assertJsonFragment(['code' => (string)Response::HTTP_UNAUTHORIZED]);

        // Teacher 2 cannot access that Assignment, since he is not associated with the Course.
        $this->actingAs($this->teacherUser2)->get($this->url . '/' . $assignment->getKey())
            ->assertJsonFragment(['code' => (string)Response::HTTP_UNAUTHORIZED]);
    }

    /**
     * Test the Teacher can update the Assignment name.
     */
    public function testAssignmentUpdate()
    {
        $assignment = Assignment::factory(['course_id' => $this->course->getKey()])->create();

        // Other teacher can not change the assignment.
        $this->actingAs($this->teacherUser2)->patchJson(
            $this->url . '/' . $assignment->getKey(),
            [
                'data' => [
                    'attributes' => [
                        'name' => 'Assignment 2',
                    ]
                ]
            ]
        )->assertJsonFragment(['message' => 'Unauthorized']);

        // Primary teacher can edit the Assignment.
        $this->actingAs($this->teacherUser1)->patchJson(
            $this->url . '/' . $assignment->getKey(),
            [
                'data' => [
                    'attributes' => [
                        'name' => 'Assignment 2',
                    ]
                ]
            ]
        )->assertJsonFragment(['name' => 'Assignment 2']);
    }

    public function testDeleteAssignment()
    {
        $assignment = Assignment::factory(['course_id' => $this->course->getKey()])->create();

        $this->delete($this->url . '/' . $assignment->getKey())->assertStatus(Response::HTTP_ACCEPTED);

        // Validate soft-deleted models count.
        $this->assertEquals(0, Assignment::all()->count());
        $this->assertEquals(1, Assignment::withTrashed()->count());
    }
}
