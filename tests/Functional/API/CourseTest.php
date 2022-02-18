<?php

namespace Tests\Functional\API;

use App\Http\ApiCodes;
use App\Http\Controllers\API\Transformers\CourseTransformer;
use App\Models\Calendar;
use App\Models\Course;
use App\Models\Group;
use App\Models\Term;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Response;
use RRule\RRule;
use Tests\ApiTestCase;

class CourseTest extends ApiTestCase
{
    private Term $term;

    private static array $customData = [
        'logo' => 'somelogo.svg',
        'color' => 'black',
        'description' => 'Here is the Class description',
        'location' => 'Room 101',
    ];

    public function setUp(): void
    {
        parent::setUp();
        // Disable auditing from this point on
//        Course::disableAuditing();
        Course::withTrashed()->get()->each(function (Course $item) {
            $item->forceDelete();
        });

        $this->assertTrue($this->teacherUser1->can('course.create'));
        $this->actingAs($this->teacherUser1);

        $this->term = Term::factory([
            'start_at' => Carbon::create(2030)->startOfYear(),
            'end_at' => Carbon::create(2030)->startOfYear()->endOfMonth(),
        ])->create();
    }

    /**
     * Test Course wouldn't be created without a name.
     */
    public function testCreateCourseNoName()
    {
        $this->post('/api/courses')
            ->assertJsonFragment(['title' => 'Failed model validation'])
            ->assertJsonFragment(['message' => 'data.attributes.name: The data.attributes.name field is required.'])
            ->assertJsonFragment(['code' => ApiCodes::MODEL_VALIDATION_ERROR]);
    }

    public function testCreateCourseNoJson()
    {
        // This would POST NOT json format!
        $this->post('/api/courses', ['data' => ['attributes' => ['name' => 'something']]])
            ->assertJsonFragment(['title' => 'Invalid JSON API request object'])
            ->assertJsonFragment(['message' => 'Malformed Json API Request Exception'])
            ->assertJsonFragment(['code' => ApiCodes::MALFORMED_REQUEST]);
    }

    /**
     * This negative test will validate foreign key for term_id.
     */
    public function testCreateWrongTerm()
    {
        $this->postJson('/api/courses', [
            'data' => [
                'attributes' => [
                    'term_id' => 666,
                    'name' => 'Chemistry',
                ]
            ]
        ])
            ->assertJsonFragment(['code' => ApiCodes::DB_ERROR]);
    }


    /**
     * Creates a new Course
     * @throws \Throwable
     */
    public function testCreateCourse()
    {
        /** @var User $student */
        $student = User::factory(['name' => 'Student B'])->create()->assignRole(Course::ROLE_STUDENT);
        $student2 = User::factory(['name' => 'Student C'])->create()->assignRole(Course::ROLE_STUDENT);
//        $teacherB = User::factory(['name' => 'Teacher B'])->create()->assignRole(Course::ROLE_TEACHER);

        $response = $this->postJson('/api/courses?include=users', [
            'data' => [
                'attributes' => [
                    'term_id' => $this->term->getKey(),
                    'name' => 'Ch',
                    'description' => '<h1>Chemistry</h1>',
                    'data' => self::$customData,
                    // pass users array of students to be added
                    'users' => [
                        ['user_id' => $student->getKey(), 'course_role' => Course::ROLE_STUDENT],
                        ['user_id' => $student2->getKey(), 'course_role' => Course::ROLE_STUDENT],
                        ['user_id' => $this->teacherUser1->getKey(), 'course_role' => Course::ROLE_TEACHER],
                    ]
                ]
            ]
        ])
            ->assertJsonFragment(['type' => CourseTransformer::JSON_OBJ_TYPE])
            // Validates both Students has been added.
            ->assertJsonFragment(['name' => 'Student C'])
            ->assertJsonFragment(['name' => 'Student B'])
            ->assertJsonFragment(['course_role' => Course::ROLE_STUDENT])
            ->assertJsonFragment(['name' => 'Teacher A'])
            ->assertJsonFragment(['description' => '<h1>Chemistry</h1>'])
            ->assertJsonFragment(['course_role' => Course::ROLE_TEACHER]);

        $result = $response->decodeResponseJson()['data']['attributes'];
        $this->assertNotNull('id');
        $this->assertNotNull($result['created_at']);
        $this->assertNotNull($result['updated_at']);
        $this->assertEquals(self::$customData, $result['data']);

        $this->assertNull($result['start_at']);

        // Validate the issue when primary_teacher and teacher are the same, this should not give a duplicated Course.
        $this->get('/api/courses')->assertJsonFragment(['count' => 1]);

        // Verify Student got notification
        $this->actingAs($student)->get('/api/notifications')
            ->assertJsonFragment(['title' => 'You\'ve added to the Course'])
            ->assertJsonFragment(['entity_type' => 'course'])
            ->assertJsonFragment(['total' => 1]);
    }

    public function testAddCourseWrongUser()
    {
        $course = Course::factory(['name' => 'Chemistry'])->create();

        /** @var User $student */
        $student3 = User::factory(['name' => 'Student C'])->create();//->assignRole('Student');

        // Add this Student to the Course.
        $this->postJson(
            "/api/courses/{$course->id}/add_students?include=users",
            [
                'users' => [
                    ['user_id' => $student3->getKey(), 'course_role' => Course::ROLE_STUDENT],
                ]
            ]
        )->assertJsonFragment(['message' => 'User is not a Student or Teacher']);
    }

    public function testAddCourseStudents()
    {
        $course = Course::factory(['name' => 'Chemistry'])
            ->hasAttached(User::factory(['name' => 'Student']), ['role' => Course::ROLE_STUDENT], 'students')
            ->create();

        /** @var User $student */
        $student = User::factory(['name' => 'Student B'])->create()->assignRole(Course::ROLE_STUDENT);
        $student2 = User::factory(['name' => 'Student C'])->create()->assignRole(Course::ROLE_STUDENT);

        $this->assertCount(1, Course::all());
        /** @var User $student0 */
        $student0 = User::whereName('Student')->first();
        $student0->assignRole(Course::ROLE_STUDENT);

        // Add this Student to the Course.
        $this->postJson(
            "/api/courses/{$course->id}/add_students?include=users",
            [
                'users' => [
                    ['user_id' => $student->getKey(), 'course_role' => Course::ROLE_STUDENT],
                    ['user_id' => $student2->getKey(), 'course_role' => Course::ROLE_STUDENT],
                    ['user_id' => User::whereName('Student')->first()->getKey(), 'course_role' => Course::ROLE_STUDENT],
                    [
                        'user_id' => User::whereName('Teacher A')->first()->getKey(),
                        'course_role' => Course::ROLE_PRIMARY_TEACHER
                    ],
                ]
            ]
        )->assertJsonFragment(['name' => 'Student'])
            ->assertJsonFragment(['name' => 'Student B'])
            ->assertJsonFragment(['course_role' => Course::ROLE_STUDENT]);

        $this->assertCount(1, Course::all());
        $response = $this->get("/api/courses/");

        // Delete Student from the Course.
        $this->postJson(
            "/api/courses/{$course->id}/delete_students?include=users",
            [
                'users' => [
                    // Delete Student B.
                    ['user_id' => $student->getKey()],
                ]
            ]
        )->assertJsonFragment(['name' => 'Student'])
            ->assertDontSeeText('Student B')
            ->assertJsonFragment(['course_role' => Course::ROLE_STUDENT]);
    }

    /**
     * Validate we can get the Course with `course.view` permission
     */
    public function testGetCourse()
    {
        $student = User::factory(['name' => 'Student A'])->create()->assignRole(Course::ROLE_STUDENT);

//        $course = Course::factory(['name' => 'Chem'])->create();
        $course = Course::factory(['name' => 'Chem'])
            ->hasAttached($student, ['role' => Course::ROLE_STUDENT], 'students')
            ->create();

        $this->get("/api/courses/{$course->id}?include=users,term")
            ->assertJsonFragment(['name' => 'Chem'])
            // teacher role should be included in User relationship.
            ->assertJsonFragment(['course_role' => 'primary_teacher'])
            ->assertJsonFragment(['id' => $this->teacherUser1->getKey()])
            // Here we check if Term is included
            ->assertJsonFragment(['type' => 'term'])
            ->assertJsonFragment(['name' => $course->term->name]);

        // Remove permission and the course should be no more accessible
        $this->teacherUser1->removeRole(Course::ROLE_TEACHER);

        $this->get("/api/courses/{$course->id}")
            ->assertJsonFragment(['code' => (string)Response::HTTP_UNAUTHORIZED]);

        // Teacher 2 cannot access that Course, since he is not associated with the Course.
        $this->actingAs($this->teacherUser2)->get("/api/courses/{$course->id}")
            ->assertJsonFragment(['code' => (string)Response::HTTP_UNAUTHORIZED]);

        $this->actingAs($student)->get("/api/courses/{$course->id}")
            ->assertJsonFragment(['name' => 'Chem']);
    }

    /**
     * Retrieve Courses list via API call.
     *
     * @return void
     * @throws \Throwable
     */
    public function testGetCourses()
    {
        $this->actingAs($this->teacherUser2);
        Course::factory(['name' => 'Other'])->create();

        $this->actingAs($this->teacherUser1);
        Course::factory(['name' => 'Chem'])->forTerm(['name' => 'Term 2020'])->create();
        Course::factory(['name' => 'Math'])->forTerm(['name' => 'Term 2021'])->create();

        // Validate User included as well.
        $this->get('/api/courses?include=users')
            ->assertJsonFragment(['name' => $this->teacherUser1->name])
            ->assertJsonFragment(['name' => 'Math'])
            ->assertJsonFragment(['name' => 'Chem'])
            ->assertJsonFragment(['name' => $this->teacherUser1->name])
            // Second teacher name should not be seen.
            ->assertDontSeeText($this->teacherUser2->name)
            ->assertJsonFragment(['course_role' => 'primary_teacher']);

        // Validate Term includes, both Courses should be displayed.
        $this->get('/api/courses?include=term')
            ->assertJsonFragment(['name' => 'Term 2020'])
            ->assertJsonFragment(['name' => 'Term 2021']);
    }

    /**
     * Test Course filter for the given Term.
     */
    public function testFilterTermCourses()
    {
        $this->actingAs($this->teacherUser1);
        $course = Course::factory(['name' => 'Chem'])->forTerm(['name' => 'Term 2020'])->create();
        $this->actingAs($this->teacherUser2);
        Course::factory(['name' => 'Other'])->for($course->term)->create();
        // Here we'll have two Courses for the same Term, but for other teachers.
        $this->actingAs($this->teacherUser1);

        // Here is another Course for totally other Term. Should not be seen.
        Course::factory(['name' => 'Math'])->forTerm(['name' => 'Term 2021'])->create();

        $this->get("/api/terms/{$course->term->id}/courses?include=term")
            ->assertJsonFragment(['name' => 'Chem'])
            ->assertJsonFragment(['name' => 'Term 2020'])
            // Only one course should be seed for teacherUser1
            ->assertJsonFragment(['count' => 1]);
    }

    /**
     * Test the Teacher can update the Course name.
     */
    public function testCourseUpdate()
    {
        $course1 = Course::factory(['name' => 'Chem1'])
            ->hasAttached($this->teacherUser2, ['role' => Course::ROLE_TEACHER], 'teachers')
            ->create();

        // Primary teacher can change the name.
        $response = $this->patchJson(
            '/api/courses/' . $course1->getKey(),
            [
                'data' => [
                    'attributes' => [
                        'name' => 'Chem2',
                        'description' => '<h1>Chemistry</h1>',
                        'data' => self::$customData,
                    ]
                ]
            ]
        );
        $response->assertJsonFragment(['name' => 'Chem2'])
            ->assertJsonFragment(['description' => '<h1>Chemistry</h1>']);
        $this->assertEquals(self::$customData, $response->decodeResponseJson()['data']['attributes']['data']);

        // Secondary teacher can change the name.
        $response = $this->actingAs($this->teacherUser2)->patchJson(
            '/api/courses/' . $course1->getKey(),
            [
                'data' => [
                    'attributes' => [
                        'name' => 'Chem1',
                        // but not the custom data.
                        'data' => ['logo' => 'somelogo2.svg']
                    ]
                ]
            ]
        );
        $response->assertJsonFragment(['name' => 'Chem1']);
        // Secondary teacher should not be able to change Custom Data.
        $this->assertEquals(self::$customData, $response->decodeResponseJson()['data']['attributes']['data']);
    }

    public function testDeleteCourse()
    {
        $response = $this->postJson('/api/courses', ['data' => ['attributes' => [
            'term_id' => $this->term->getKey(),
            'rrule' => "DTSTART=" . now()->toDateString() . ";FREQ=DAILY;BYDAY=MO,WE",
            'name' => 'Chemistry',
        ]]])
            ->assertJsonFragment(['type' => CourseTransformer::JSON_OBJ_TYPE]);

        $courseId = $response->decodeResponseJson()['data']['id'];
        $this->assertEquals(9, Calendar::all()->count());

        $this->delete("/api/courses/{$courseId}")->assertStatus(Response::HTTP_ACCEPTED);

        // Validate soft-deleted models count.
        $this->assertEquals(0, Course::all()->count());
        $this->assertEquals(1, Course::withTrashed()->count());

        $this->assertEquals(0, Calendar::all()->count());
        $this->assertEquals(9, Calendar::withTrashed()->count());
    }
}
