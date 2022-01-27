<?php

namespace Tests\Functional\API;

use App\Http\Controllers\API\Transformers\CourseTransformer;
use App\Models\Course;
use App\Models\Group;
use App\Models\Room;
use App\Models\Term;
use App\Models\User;
use Carbon\Carbon;
use Tests\ApiTestCase;

class CourseEventsTest extends ApiTestCase
{
    private Term $term;

    public function setUp(): void
    {
        parent::setUp();
        // Disable auditing from this point on
//        Course::disableAuditing();
        Course::truncate();

        $this->assertTrue($this->teacherUser1->can('course.create'));
        $this->actingAs($this->teacherUser1);

        $this->term = Term::factory([
            'start_at' => Carbon::create(2030)->startOfYear(),
            'end_at' => Carbon::create(2030)->startOfYear()->endOfMonth(),
        ])->create();
    }

    public function testCourseIncludeColor()
    {
        $response = $this->postJson('/api/courses', ['data' => ['attributes' => [
            'term_id' => $this->term->getKey(),
            'rrule' => "DTSTART=" . now()->toDateString() . ";FREQ=DAILY;BYDAY=MO,WE",
            'name' => 'Chemistry',
            'data' => ['logo' => 'somelogo.svg', 'color' => 'black'],
        ]]])
            ->assertJsonFragment(['type' => CourseTransformer::JSON_OBJ_TYPE]);
        // Example of sparse fields selection:
        // Primary entity fields have the following definition "fields[calendar]=summary,start_at,end_at"
        // Included entity fields are defines as a part of include section "include=course:fields(data|name)"
        $this->get('/api/calendars?include=course:fields(data|name),users&fields[calendar]=summary,start_at,end_at')
            ->assertDontSee('student_count');
    }

    /**
     * Creates a new Course, attach a few Students for the Course and check Calendar Events have these
     * Students as attendees.
     * @throws \Throwable
     */
    public function testCreateCourse()
    {
        $response = $this->postJson('/api/courses', ['data' => ['attributes' => [
            'term_id' => $this->term->getKey(),
            'rrule' => "DTSTART=" . now()->toDateString() . ";FREQ=DAILY;BYDAY=MO,WE",
            'name' => 'Chemistry',
        ]]])
            ->assertJsonFragment(['type' => CourseTransformer::JSON_OBJ_TYPE]);

        $result = $response->decodeResponseJson()['data']['attributes'];
        $this->assertNotNull('id');
        $this->assertNotNull($result['created_at']);
        $this->assertNotNull($result['rrule']);
        $this->assertNotNull($result['updated_at']);
        $this->assertNull($result['start_at']);
        $courseId = $response->decodeResponseJson()['data']['id'];

        $this->teacherUser1->givePermissionTo(['calendar.index', 'calendar.view']);

        // There should be 9 events of Chemistry generated in the given Term
        $this->get('/api/calendars')
            ->assertJsonFragment(['total' => 9])
            // We should have slug for the type.
            ->assertJsonFragment(['type' => 'course'])
            ->assertJsonFragment(['summary' => 'Chemistry']);

        /** @var User $student */
        $student = User::factory(['name' => 'Student B'])->create()->assignRole(Course::ROLE_STUDENT);
        $student2 = User::factory(['name' => 'Student C'])->create()->assignRole(Course::ROLE_STUDENT);

        // Add these Students to the Course.
        $this->postJson(
            "/api/courses/$courseId/add_students?fields[course]=student_count",
            ['users' => [
                ['user_id' => $student->getKey(), 'course_role' => Course::ROLE_STUDENT],
                ['user_id' => $student2->getKey(), 'course_role' => Course::ROLE_STUDENT],
            ]]
        )->assertJsonFragment(['student_count' => 2]);

        // Calendar Events now should have these Students as attendees.
        $this->get('/api/calendars?per_page=1&include=users')
            // Roles should be seen in attendees.
            ->assertJsonFragment(['role' => Course::ROLE_PRIMARY_TEACHER])
            ->assertJsonFragment(['role' => Course::ROLE_STUDENT])
            ->assertJsonFragment(['name' => 'Teacher A'])
            ->assertJsonFragment(['name' => 'Student B'])
            ->assertJsonFragment(['name' => 'Student C'])
            ->assertJsonFragment(['total' => 9])
            ->assertJsonFragment(['summary' => 'Chemistry']);

        // Delete one of Students from the Course.
        $this->postJson(
            "/api/courses/$courseId/delete_students?fields[course]=student_count",
            ['users' => [
                // Delete Student B
                ['user_id' => $student->getKey()],
            ]]
        )->assertJsonFragment(['student_count' => 1]);

//        $this->get("/api/courses/$courseId?include=users,term");

        // Calendar Events now should have these Students as attendees.
        $this->get('/api/calendars?per_page=3&include=users')
            ->assertJsonFragment(['name' => 'Teacher A'])
            // Student C should be still attached
            ->assertJsonFragment(['name' => 'Student C'])
            // but Student B should be gone.
            ->assertDontSeeText('Student B')
            ->assertJsonFragment(['total' => 9])
            ->assertJsonFragment(['summary' => 'Chemistry']);

        $this->get("/api/calendars?course_id=$courseId&start_at=2030-01-09&end_at=2030-01-20&include=course")
            ->assertJsonFragment(['total' => 3]);
    }

    public function testCreateCourseRruleArray()
    {
        /** @var Room $room */
        $room1 = Room::factory(['name' => 'Room A'])->create();
        $room2 = Room::factory(['name' => 'Room B'])->create();

        $response = $this->postJson('/api/courses', ['data' => ['attributes' => [
            'term_id' => $this->term->getKey(),
            // Passing two rules
            'rrule' => [
                // Also attach different rooms.
                "DTSTART=10:00;DTEND=11:00;FREQ=DAILY;BYDAY=MO,WE;ROOM=" . $room1->getKey(),
                "DTSTART=13:00;DTEND=14:00;FREQ=DAILY;BYDAY=TU,TH;ROOM=" . $room2->getKey(),
            ],
            'name' => 'Chemistry',
        ]]])
            ->assertJsonFragment(['type' => CourseTransformer::JSON_OBJ_TYPE]);

        $result = $response->decodeResponseJson()['data']['attributes'];
        $this->assertNotNull('id');
        $this->assertNotNull($result['created_at']);
        $this->assertNotNull($result['rrule']);
        $this->assertNotNull($result['updated_at']);
        $this->assertNull($result['start_at']);

        $this->teacherUser1->givePermissionTo(['calendar.index', 'calendar.view']);

        // There should be 19 events of Chemistry generated in the given Term
        $this->get('/api/calendars?include=room')
            // There should be rooms attached to the Calendar Events.
            ->assertJsonFragment(['name' => "Room A"])
            ->assertJsonFragment(['name' => "Room B"])
            ->assertJsonFragment(['total' => 19])
            ->assertJsonFragment(['summary' => 'Chemistry']);
    }
    /**
     * Test the Teacher can update the Course name and all further Events would reflect the change.
     */
    public function testCourseUpdateName()
    {
        $response = $this->postJson('/api/courses', ['data' => ['attributes' => [
            'term_id' => $this->term->getKey(),
            'rrule' => "DTSTART=10:15;DTEND=11:00;FREQ=DAILY;BYDAY=MO,WE",
            'name' => 'Chemistry',
        ]]])
            ->assertJsonFragment(['type' => CourseTransformer::JSON_OBJ_TYPE]);
        $courseId = $response->decodeResponseJson()['data']['id'];

        // Primary teacher can change the name.
        $response = $this->patchJson(
            '/api/courses/' . $courseId,
            ['data' => ['attributes' => [
                'name' => 'Chem2',
            ]]]
        );
        $response->assertJsonFragment(['name' => 'Chem2']);

        $this->teacherUser1->givePermissionTo(['calendar.index', 'calendar.view']);

        // Primary teacher can change the name.
        $this->patchJson(
            '/api/courses/' . $courseId,
            [
                'data' => [
                    'attributes' => [
                        'name' => 'Chem2',
                    ]
                ]
            ]
        )
            ->assertJsonFragment(['type' => CourseTransformer::JSON_OBJ_TYPE]);


        // Calendar Events now should have the new name.
        $this->get('/api/calendars?per_page=1')
            ->assertDontSeeText('Chemistry')
            ->assertJsonFragment(['total' => 9])
            ->assertJsonFragment(['summary' => 'Chem2'])
            // Start time and duration should be applied.
            ->assertJsonFragment(['start_at' => "2030-01-02T10:15:00+00:00"])
            ->assertJsonFragment(['end_at' => "2030-01-02T11:00:00+00:00"]);
    }

    public function testCourseUpdateRrule()
    {
        /** @var User $student */
        $student = User::factory(['name' => 'Student B'])->create()->assignRole(Course::ROLE_STUDENT);
        $student2 = User::factory(['name' => 'Student C'])->create()->assignRole(Course::ROLE_STUDENT);

        // Create Course with a set of students and w/o timetable properties.
        $response = $this->postJson('/api/courses?include=users', ['data' => ['attributes' => [
            'term_id' => $this->term->getKey(),
            'name' => 'Chemistry',
            'users' => [
                ['user_id' => $student->getKey(), 'course_role' => Course::ROLE_STUDENT],
                ['user_id' => $student2->getKey(), 'course_role' => Course::ROLE_STUDENT],
            ]
        ]]])
            ->assertJsonFragment(['type' => CourseTransformer::JSON_OBJ_TYPE])
            ->assertJsonFragment(['name' => 'Student B'])
            ->assertJsonFragment(['name' => 'Student C']);

        $courseId = $response->decodeResponseJson()['data']['id'];

        $this->teacherUser1->givePermissionTo(['calendar.index', 'calendar.view']);

        // Since we haven't passed rrule, there shouldn't be Calendar Events.
        $this->get('/api/calendars?per_page=1')
            ->assertJsonFragment(['total' => 0]);

        // Add Rrule to the existing Course.
        $this->patchJson(
            '/api/courses/' . $courseId,
            [
                'data' => [
                    'attributes' => [
                        'name' => 'Chem2',
                        'rrule' => "DTSTART=10:15:00;DTEND=11:00:00;FREQ=DAILY;BYDAY=MO,WE",
                    ]
                ]
            ]
        )
            ->assertJsonFragment(['type' => CourseTransformer::JSON_OBJ_TYPE]);

        // Calendar Events now should have the new name.
        $this->get('/api/calendars?per_page=1')
            // Start time and duration should be applied.
            ->assertJsonFragment(['start_at' => "2030-01-02T10:15:00+00:00"])
            ->assertJsonFragment(['end_at' => "2030-01-02T11:00:00+00:00"])
            ->assertDontSeeText('Chemistry')
            ->assertJsonFragment(['total' => 9])
            ->assertJsonFragment(['summary' => 'Chem2'])
            // Both students should be seen as Attendees.
            ->assertJsonFragment(['user_id' => $student->getKey()])
            ->assertJsonFragment(['user_id' => $student2->getKey()]);

        // Change duration of the existing Course.
        $this->patchJson(
            '/api/courses/' . $courseId,
            ['data' => ['attributes' => [
                'name' => 'Chem2',
                'rrule' => "DTSTART=10:15:00;DTEND=10:45:00;FREQ=DAILY;BYDAY=MO,WE",
            ]]]
        )
            ->assertJsonFragment(['type' => CourseTransformer::JSON_OBJ_TYPE]);

        // Calendar Events now should have the new name.
        $this->get('/api/calendars?per_page=1')
            ->assertDontSeeText('Chemistry')
            ->assertJsonFragment(['total' => 9])
            ->assertJsonFragment(['summary' => 'Chem2'])
            // Start time and duration should be applied.
            ->assertJsonFragment(['start_at' => "2030-01-02T10:15:00+00:00"])
            // New duration should be in place.
            ->assertJsonFragment(['end_at' => "2030-01-02T10:45:00+00:00"])
            // Both students should be seen as Attendees.
            ->assertJsonFragment(['user_id' => $student->getKey()])
            ->assertJsonFragment(['user_id' => $student2->getKey()]);
    }
}
