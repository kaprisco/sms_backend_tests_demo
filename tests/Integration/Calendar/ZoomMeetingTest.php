<?php

namespace Tests\Integration\Calendar;

use App\Mail\CalendarInvitation;
use App\Mail\IcsContents;
use App\Models\Calendar;
use App\Models\Course;
use App\Models\Term;
use App\Models\User;
use Carbon\Carbon;
use Tests\ApiTestCase;

class ZoomMeetingTest extends ApiTestCase
{
    private Term $term;

    private static array $customData = ['logo' => 'somelogo.svg', 'color' => 'black'];

    public function setUp(): void
    {
        parent::setUp();
        // Disable auditing from this point on
//        Course::disableAuditing();
        Course::withTrashed()->get()->each(function (Course $item) {
            $item->forceDelete();
        });

        $this->assertTrue($this->teacherUser1->can('course.create'));

        $this->term = Term::factory([
            'start_at' => Carbon::now()->startOfDay(),
            'end_at' => Carbon::now()->endofDay(),
        ])->create();
    }

    public function testMeetingCreate()
    {
        /** @var \App\Models\User $student */
        $student = User::factory(['name' => 'Student B'])->create()->assignRole(Course::ROLE_STUDENT);
        $student2 = User::factory(['name' => 'Student C'])->create()->assignRole(Course::ROLE_STUDENT);

        $this->actingAs($this->teacherUser1);

        $response = $this->postJson('/api/courses', [
            'data' => [
                'attributes' => [
                    'term_id' => $this->term->getKey(),
                    'name' => 'Chemistry',
                    'rrule' => "DTSTART=" . now()->toDateString() . ";FREQ=DAILY;BYDAY=MO,WE",
                    'data' => self::$customData,
                    // pass users array of students to be added
                    'users' => [
                        ['user_id' => $student->getKey(), 'course_role' => Course::ROLE_STUDENT],
                        // Student 2 specifically is not included here.
                        ['user_id' => $this->teacherUser2->getKey(), 'course_role' => Course::ROLE_TEACHER],
                    ]
                ]
            ]
        ]);
//        dd(Course::first()->getAllUsers());

        $calendarId = Calendar::first()->getKey();

        // This student is not an attendee.
        $this->actingAs($student2);
        $this->get("/api/calendars/$calendarId/zoom")
            ->assertJsonFragment(['message' => 'Unauthorized']);

        $student->givePermissionTo(['calendar.index', 'calendar.view']);

        // Student is the attendee, but cannot create Meeting URL.
        $this->actingAs($student);
        $this->get("/api/calendars/$calendarId/zoom")
            ->assertJsonFragment(['title' => 'Only teachers can create Meeting URL']);

        // Teacher who is not an attendee won't be able to start the meeting.
        $this->actingAs(User::factory()->asTeacher()->create());
        $this->get("/api/calendars/$calendarId/zoom")
            ->assertJsonFragment(['title' => 'User is not an attendee']);

        $this->actingAs($this->teacherUser1);
        $this->get("/api/calendars/$calendarId/zoom")
            ->assertSeeText('join_url');

        // All other Students who are attendees also would be able to see zoom join url.
        $this->actingAs($student);
        $this->get("/api/calendars/$calendarId")
            ->assertSeeText('join_url');
    }
}
