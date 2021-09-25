<?php

namespace Tests\Functional\API;

use App\Http\ApiCodes;
use App\Models\Calendar;
use App\Models\Course;
use App\Models\Term;
use App\Models\User;
use Carbon\Carbon;
use Tests\ApiTestCase;

class CourseRollCallTest extends ApiTestCase
{
    private Course $course;

    public function setUp(): void
    {
        parent::setUp();
        // Disable auditing from this point on
        Course::disableAuditing();
        Course::truncate();

        $this->assertTrue($this->teacherUser1->can('course.create'));
        $this->actingAs($this->teacherUser1);

        /** @var User $student */
        $student = User::factory(['name' => 'Student'])->afterCreating(function (User $user) {
            $user->assignRole(Course::ROLE_STUDENT);
        })->create();

        $this->course = Course::factory([
            'rrule' => "DTSTART=" . now()->toDateString() . ";FREQ=DAILY;BYDAY=MO,WE",
            'name' => 'Chemistry'
        ])
            ->for(Term::factory([
                'start_at' => Carbon::create(2030)->startOfYear(),
                'end_at' => Carbon::create(2030)->startOfYear()->endOfMonth(),
            ])->create())
//            ->hasAttached($student, ['role' => Course::ROLE_STUDENT], 'students')
            ->create();

        $this->course->addStudents($student);
        $this->assertCount(1, $this->course->students, 'The Student should be attached');
    }

    /**
     * Creates a new Course, attach a few Students for the Course and check Calendar Events have these
     * Students as attendees.
     * @throws \Throwable
     */
    public function testRollCall()
    {
//        $response = $this->postJson('/api/courses', ['data' => ['attributes' => [
//            'term_id' => $this->term->getKey(),
//            'rrule' => "DTSTART=" . now()->toDateString() . ";FREQ=DAILY;BYDAY=MO,WE",
//            'name' => 'Chemistry',
//        ]]])
//            ->assertJsonFragment(['type' => CourseTransformer::JSON_OBJ_TYPE]);
//
//        $result = $response->decodeResponseJson()['data']['attributes'];
//        $this->assertNotNull('id');
//        $this->assertNotNull($result['created_at']);
//        $this->assertNotNull($result['updated_at']);
//        $this->assertNull($result['start_at']);
//        $courseId = $response->decodeResponseJson()['data']['id'];
        $this->teacherUser1->givePermissionTo(['calendar.index', 'calendar.view']);
        $this->actingAs($this->teacherUser1);
//        $this->actingAs($this->user);

        $this->get('/api/courses/' . $this->course->getKey() . '?include=users')
            ->assertJsonFragment(['name' => 'Student']);

        $event = Calendar::first();
//        dump($event->getKey());
        $this->get("/api/calendars/" . $event->getKey());

        /** @var User $student */
        $student1 = User::factory(['name' => 'Student B'])->create()->assignRole(Course::ROLE_STUDENT);
        $student2 = User::factory(['name' => 'Student C'])->create()->assignRole(Course::ROLE_STUDENT);

//        dump($student->getKey(), $student2->getKey());
        // Add these Students to the Course.
        $this->postJson(
            "/api/courses/" . $this->course->getKey() . "/add_students?fields[course]=student_count",
            ['users' => [
                ['user_id' => $student1->getKey(), 'course_role' => Course::ROLE_STUDENT],
                ['user_id' => $student2->getKey(), 'course_role' => Course::ROLE_STUDENT],
            ]]
        )->assertJsonFragment(['student_count' => 3]);


        // Calendar Events now should have these Students as attendees.
        $this->get("/api/calendars/" . $event->getKey(). '?include=users')
            ->assertJsonFragment(['name' => 'Student'])
            ->assertJsonFragment(['name' => 'Student B'])
            ->assertJsonFragment(['name' => 'Student C']);

        // Mark Student B as not present.
        // Student C as present.
        $payload = ['data' => ['attributes' => ['attendees' => [
            ['user_id' => $student1->getKey(), 'attendee_status' => Calendar::STATUS_REJECTED],
            ['user_id' => $student2->getKey(), 'attendee_status' => Calendar::STATUS_CONFIRMED],
        ]
        ]]];

        // The request should fail w/o proper permission.
        $this->patchJson(
            "/api/calendars/" . $event->getKey() . '?include=users',
            $payload
        )->assertJsonFragment(['message' => 'Unauthorized'])
        ->assertJsonFragment(['code' => ApiCodes::UNAUTHORIZED]);

        // Add the permission.
        $this->teacherUser1->givePermissionTo(['calendar.rollcall']);

        // Change attendee status.
        $response = $this->patchJson(
            "/api/calendars/" . $event->getKey() . '?include=users',
            $payload
        );
        $response = collect($response->decodeResponseJson()['data']['attributes']['attendees']);

        // Student2 should be present.
        $this->assertEquals(
            Calendar::STATUS_CONFIRMED,
            $response->where('user_id', $student2->getKey())->pluck('attendee_status')->first()
        );

        // $student1 should be absent.
        $this->assertEquals(
            Calendar::STATUS_REJECTED,
            $response->where('user_id', $student1->getKey())->pluck('attendee_status')->first()
        );
    }
}
