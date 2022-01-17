<?php

namespace Tests\Functional\API;

use App\Http\ApiCodes;
use App\Models\Calendar;
use App\Models\Course;
use App\Models\Role;
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
        Calendar::disableAuditing();
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
     * @throws \Throwable
     */
    public function testRollCall()
    {
        $this->teacherUser1->givePermissionTo(['calendar.index', 'calendar.view']);
        $this->actingAs($this->teacherUser1);

        $this->get('/api/courses/' . $this->course->getKey() . '?include=users')
            ->assertJsonFragment(['name' => 'Student']);

        $event = Calendar::first();

        /** @var User $student */
        $student1 = User::factory(['name' => 'Student B', 'school_id'=> $this->user->school_id])->create()
            ->assignRole(Course::ROLE_STUDENT);

        $student2 = User::factory(['name' => 'Student C', 'school_id'=> $this->user->school_id])->create()
            ->assignRole(Course::ROLE_STUDENT);

//        dump($student->getKey(), $student2->getKey());
        // Add these Students to the Course.
        $this->postJson(
            "/api/courses/" . $this->course->getKey() . "/add_students?fields[course]=student_count",
            ['users' => [
                ['user_id' => $student1->getKey(), 'course_role' => Course::ROLE_STUDENT],
                ['user_id' => $student2->getKey(), 'course_role' => Course::ROLE_STUDENT],
            ]]
        )->assertJsonFragment(['student_count' => 3]);

        // List events as a Student
        $this->actingAs($student2)
            ->get("/api/calendars/?fields[calendar]=start_at,end_at,summary,my_status")
            ->assertJsonFragment(['count' => 9]);

        // Calendar Events now should have these Students as attendees.
        $this->get("/api/calendars/" . $event->getKey(). '?include=users')
            // Role should be seen in attendees.
            ->assertJsonFragment(['role' => Course::ROLE_STUDENT])
            ->assertJsonFragment(['name' => 'Student'])
            ->assertJsonFragment(['name' => 'Student B'])
            ->assertJsonFragment(['name' => 'Student C']);

        // Mark Student B as not present.
        // Student C as present.
        $payload = ['data' => ['attributes' => ['attendees' => [
            ['user_id' => $student1->getKey(), 'attendee_status' => Calendar::STATUS_REJECTED, 'note' => 'kapusta'],
            ['user_id' => $student2->getKey(), 'attendee_status' => Calendar::STATUS_CONFIRMED],
        ]
        ]]];

        $this->teacherUser1->removeRole(Course::ROLE_TEACHER);

        // The request should fail w/o proper permission.
        $this->patchJson(
            "/api/calendars/" . $event->getKey() . '?include=users',
            $payload
        )->assertJsonFragment(['message' => 'Unauthorized'])
            ->assertJsonFragment(['title' => 'Your roll call update request is unauthorized'])
            ->assertJsonFragment(['code' => ApiCodes::UNAUTHORIZED]);

        // Add the permission.
        $this->teacherUser1->givePermissionTo(['calendar.rollcall']);
        $this->teacherUser1->assignRole(Course::ROLE_TEACHER);

        // Test passing wrong attendee ID
        $this->actingAs($this->teacherUser1)->patchJson(
            "/api/calendars/" . $event->getKey() . '?include=users',
            [
                'data' => [
                    'attributes' => [
                        'attendees' => [
                            ['user_id' => 666],
                        ]
                    ]
                ]
            ]
        )->assertJsonFragment(['detail' => 'Attendee is not exists, cannot change the status!']);

        // Change attendee status.
        $response = $this->actingAs($this->teacherUser1)->patchJson(
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

        // Optional note attribute should be recorded along with the status.
        $this->assertEquals(
            'kapusta',
            $response->where('user_id', $student1->getKey())->pluck('note')->first()
        );

        $this->assertNotNull(
            $response->where('user_id', $student1->getKey())->pluck('updated_at')->first()
        );

        // List events as a Student
        $this->actingAs($student2)
            ->get("/api/calendars/?fields[calendar]=start_at,end_at,summary,my_status")
            ->assertJsonFragment(['count' => 9]);

        // Retrieve attendee status as a Student1
        $this->actingAs($student1)
            ->get("/api/calendars/{$event->id}?fields[calendar]=start_at,end_at,summary,my_status")
            ->assertJsonFragment(['my_status' => Calendar::STATUS_REJECTED]);

        // Retrieve attendee status as a Student2
        $this->actingAs($student2)
            ->get("/api/calendars/{$event->id}?fields[calendar]=start_at,end_at,summary,my_status")
            ->assertJsonFragment(['my_status' => Calendar::STATUS_CONFIRMED]);
    }
}
