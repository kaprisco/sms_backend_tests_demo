<?php

namespace Tests\Functional\API;

use App\Http\ApiCodes;
use App\Models\Calendar;
use App\Models\Calendars\CalendarTeacherParent;
use App\Models\Calendars\CalendarTimeslotEvent;
use App\Models\Course;
use App\Models\Room;
use App\Models\School;
use App\Models\User;
use Carbon\Carbon;
use Tests\ApiTestCase;

class TeacherMeetingRequestTest extends ApiTestCase
{
    public function testUnauthorizedCreate()
    {
        $response = $this->postJson(
            '/api/meetings/teacher',
            ['data' => ['attributes' => []]]
        );
        $response->assertJsonFragment(['message' => 'Unauthorized']);
        $response->assertJsonFragment(['code' => ApiCodes::UNAUTHORIZED]);
    }

    public function testModelRequirements()
    {
        $this->user->givePermissionTo(['meeting.teacher_parent.create', 'meeting.teacher_parent.view']);
        $response = $this->postJson(
            '/api/meetings/teacher',
            ['data' => ['attributes' => []]]
        );
        $response->assertJsonFragment(['title' => 'Failed model validation'])
            ->assertJsonFragment([
                'message' => 'data.attributes.reason: The data.attributes.reason field is required.'
            ])
            ->assertJsonFragment([
                'message' => 'data.attributes.timeslot_id: The data.attributes.timeslot id ' .
                    'field is required when data.attributes.start at is not present.'
            ])
            ->assertJsonFragment([
                'message' => 'data.attributes.start_at: The data.attributes.start at field is required when ' .
                    'data.attributes.timeslot id is not present.'
            ]);
    }

    public function testParentReject()
    {
        $startAt = Carbon::now()->addHour()->startOfHour();

        $meeting = CalendarTeacherParent::factory()->create([
            'organizer_id' => $this->teacherUser1->getKey(),
            'school_id' => $this->teacherUser1->school_id,
            'reason' => 'Something important',
            'summary' => 'I would like to discuss the following...',
            'start_at' => (string)$startAt,
            'end_at' => $startAt->addMinutes(30),
            'updated_at' => now()->subYear(),
        ]);
        $meeting->attendees()->sync([$this->parentUser->getKey()]);

        $this->actingAs($this->parentUser);

        $response = $this->patchJson(
            '/api/calendars/' . $meeting->getKey() . '/change_status',
            [
                'data' => [
                    'attributes' => [
                        'status' => Calendar::STATUS_REJECTED,
                    ]
                ]
            ]
        );
        $response->assertJsonFragment(['attendee_status' => Calendar::STATUS_REJECTED]);
    }

    public function testParentConfirmation()
    {
        $startAt = Carbon::now()->addHour()->startOfHour();

        $meeting = CalendarTeacherParent::factory()->create([
            'organizer_id' => $this->teacherUser1->getKey(),
            'school_id' => $this->teacherUser1->school_id,
            'reason' => 'Something important',
            'summary' => 'I would like to discuss the following...',
            'start_at' => (string)$startAt,
            'end_at' => $startAt->addMinutes(30),
            'updated_at' => now()->subYear(),
        ]);
        $meeting->attendees()->sync([$this->parentUser->getKey()]);

        $this->actingAs($this->parentUser);

        $response = $this->patchJson(
            '/api/meetings/teacher/' . $meeting->getKey() . '/change_status',
            [
                'data' => [
                    'attributes' => [
                        'status' => Calendar::STATUS_CONFIRMED,
                    ]
                ]
            ]
        );
        $response->assertJsonFragment(['attendee_status' => Calendar::STATUS_CONFIRMED])
            ->assertJsonFragment(['status' => CalendarTeacherParent::STATUS_CONFIRMED]);

        $this->parentUser->removeRole(Course::ROLE_PARENT);

        $response = $this->patchJson(
            '/api/meetings/teacher/' . $meeting->getKey() . '/change_status',
            [
                'data' => [
                    'attributes' => [
                        'status' => Calendar::STATUS_CONFIRMED,
                    ]
                ]
            ]
        );
        $response->assertJsonFragment(['message' => 'Unauthorized']);

        // There should be 2 audit records for the meeting item.
        $this->assertCount(2, $meeting->audits);

        // Try to change the status from other Parent
        $this->actingAs($this->parentUser2);

        $response = $this->patchJson(
            '/api/meetings/teacher/' . $meeting->getKey() . '/change_status',
            [
                'data' => [
                    'attributes' => [
                        'status' => Calendar::STATUS_CONFIRMED,
                    ]
                ]
            ]
        );
        $response->assertJsonFragment(['title' => 'Exception: Exception'])
            ->assertJsonFragment(['code' => '0']);
    }

    public function testTeacherMeetingStudentWithoutParents()
    {
        $startAt = Carbon::now()->addHour()->startOfHour();

        // Pre-create Student without any Parents.
        /** @var User $student */
        $student = User::factory(['name' => 'Student A'])->asStudent()->create();

        $this->actingAs($this->teacherUser1);
        $response = $this->postJson(
            '/api/meetings/teacher',
            [
                'data' => [
                    'attributes' => [
                        'reason' => 'Something important',
                        // Here we are passing Student as attendee.
                        'attendees' => [['user_id' => $student->getKey()]],
                        'start_at' => (string)$startAt,
                    ]
                ]
            ]
        );

        $response->assertJsonFragment(['title' => 'All attendees don\'t have any Parent!']);
    }

    public function testTeacherMeetingTimeslotRequest()
    {
        $startAt = Carbon::now()->addHour()->startOfHour();

        $timeSlotAlien = CalendarTimeslotEvent::factory()->create(
            [
                'school_id' => $this->teacherUser1->school_id,
                'start_at' => $startAt,
                'end_at' => $startAt->addMinutes(666),
            ]
        );

        // Pre-create Student with two Parents.
        /** @var User $student */
        $student = User::factory(['name' => 'Student A'])->asStudent()->create();
        $student->parents()->attach([$this->parentUser->getKey(), $this->parentUser2->getKey()]);

        $this->actingAs($this->teacherUser1);

        $timeSlotTeacher = CalendarTimeslotEvent::factory()->create(
            [
                'school_id' => $this->teacherUser1->school_id,
                //                'start_at' => $startAt,
                //                'end_at' => $startAt->addMinutes(666),
            ]
        );
        $timeSlotTeacher->start_at = $startAt;
        $timeSlotTeacher->end_at = $startAt->copy()->addMinutes(6);
        $timeSlotTeacher->location = [
            'type' => 'location',
            'location_room' => '303',
        ];
        $timeSlotTeacher->save();

        // This is passing wrong Timeslot belonging to other User.
        $response = $this->postJson(
            '/api/meetings/teacher',
            [
                'data' => [
                    'attributes' => [
                        'reason' => 'Something important',
                        // Here we are passing Student as attendee.
                        'attendees' => [['user_id' => $student->getKey()]],
                        'timeslot_id' => $timeSlotAlien->getKey(),
                    ]
                ]
            ]
        )->assertJsonFragment(['title' => 'You aren\'t provided a valid Timeslot!']);

        $timeSlotTeacher->status = CalendarTimeslotEvent::STATUS_BUSY;
        $timeSlotTeacher->save();

        $response = $this->postJson(
            '/api/meetings/teacher',
            [
                'data' => [
                    'attributes' => [
                        'reason' => 'Something important',
                        // Here we are passing Student as attendee.
                        'attendees' => [['user_id' => $student->getKey()]],
                        'timeslot_id' => $timeSlotTeacher->getKey(),
                    ]
                ]
            ]
        )->assertJsonFragment(['title' => 'This Timeslot is not available!']);

        $timeSlotTeacher->status = CalendarTimeslotEvent::STATUS_AVAILABLE;
        $timeSlotTeacher->save();

        $response = $this->postJson(
            '/api/meetings/teacher?include=timeslot',
            [
                'data' => [
                    'attributes' => [
                        'reason' => 'Something important',
                        // Here we are passing Student as attendee.
                        'attendees' => [['user_id' => $student->getKey()]],
                        'timeslot_id' => $timeSlotTeacher->getKey(),
                    ]
                ]
            ]
        );

        $calendarEventId = $response->decodeResponseJson()['data']['id'];
        $response->assertJsonFragment(['reason' => 'Something important'])
            // Here I validated we could create interview w/o summary
            ->assertJsonFragment(['summary' => ''])
            ->assertJsonFragment(['status' => Calendar::STATUS_UNCONFIRMED])
            ->assertJsonFragment(['organizer_user_id' => $this->teacherUser1->getKey()])
            ->assertJsonFragment(['start_at' => $startAt->toIso8601String()])
            ->assertJsonFragment(['end_at' => $startAt->addMinutes(6)->toIso8601String()]);

        $attendeesResponse = $response->decodeResponseJson()['data']['attributes']['attendees'];
        // As the result Student attendee would be resolved to Parents.
        foreach ($attendeesResponse as $attendee) {
            if (in_array($attendee['user_id'], [$this->parentUser->getKey(), $this->parentUser2->getKey()])) {
                $this->assertEquals(Calendar::STATUS_UNCONFIRMED, $attendee['attendee_status']);
            }
            // And Student would be optional.
            if ($attendee['user_id'] === $student->getKey()) {
                $this->assertEquals(Calendar::STATUS_OPTIONAL, $attendee['attendee_status']);
            }
        }
        $this->assertEquals(CalendarTimeslotEvent::STATUS_BUSY, $timeSlotTeacher->refresh()->status);

        // Validate relationship
        $calendarEvent = CalendarTeacherParent::find($calendarEventId);
        $this->assertEquals(CalendarTimeslotEvent::STATUS_BUSY, $calendarEvent->timeslot->status);

        // Check notifications here.
        $this->actingAs($this->teacherUser1)->get('/api/notifications')
            ->assertJsonFragment(["title" => "Teacher -> Parent meeting initiated by Teacher A <teacherA@gmail.com>"]);

        // Both Parents should get the notification.
        $this->actingAs($this->parentUser)->get('/api/notifications')
            ->assertJsonFragment(["count" => 1]);
        $this->actingAs($this->parentUser2)->get('/api/notifications')
            ->assertJsonFragment(["count" => 1]);

        // when CalendarEvent is deleted - timeslot should get back to avail status.
        $response = $this->actingAs($this->teacherUser1)->deleteJson('/api/meetings/teacher/' . $calendarEventId);

        $this->assertEquals(CalendarTimeslotEvent::STATUS_AVAILABLE, $calendarEvent->timeslot->refresh()->status);
        // TODO test for cancelling notifications.
    }

    public function testTeacherMeetingIncorrectRoom()
    {
        $startAt = Carbon::now()->addHour()->startOfHour();

        // Pre-create Student with two Parents.
        /** @var User $student */
        $student = User::factory(['name' => 'Student A'])->asStudent()->create();
        $student->parents()->attach([$this->parentUser->getKey(), $this->parentUser2->getKey()]);

        /** @var Room $room2 */
        $room2 = Room::factory(['name' => 'Room B'])->create();
        $room2->school_id = School::factory(['name' => 'School B'])->create()->getKey();
        $room2->save();

        $this->actingAs($this->teacherUser1);
        $response = $this->postJson(
            '/api/meetings/teacher?include=room',
            [
                'data' => [
                    'attributes' => [
                        'reason' => 'Something important',
                        // Here we are passing Student as attendee.
                        'attendees' => [['user_id' => $student->getKey()]],
                        // Specifically doesn't pass summary here
                        //                'summary' => 'I would like to discuss the following...',
                        'start_at' => (string)$startAt,
                        'end_at' => (string)$startAt->copy()->addMinutes(10),
                        'room_id' => $room2->getKey(),
                    ]
                ]
            ]
        )->assertJsonFragment(['detail' => 'Room does not exists!']);
    }

    /**
     * Teacher requests a meeting by giving custom time (Without timeslot).
     *
     * @return void
     * @throws \Throwable
     */
    public function testTeacherMeetingRequest()
    {
        $startAt = Carbon::now()->addHour()->startOfHour();

        // Pre-create Student with two Parents.
        /** @var User $student */
        $student = User::factory(['name' => 'Student A'])->asStudent()->create();
        $student->parents()->attach([$this->parentUser->getKey(), $this->parentUser2->getKey()]);

        /** @var Room $room */
        $room = Room::factory(['name' => 'Room A'])->create();

        $this->actingAs($this->teacherUser1);
        $response = $this->postJson(
            '/api/meetings/teacher?include=room',
            [
                'data' => [
                    'attributes' => [
                        'reason' => 'Something important',
                        // Here we are passing Student as attendee.
                        'attendees' => [['user_id' => $student->getKey()]],
                        // Specifically doesn't pass summary here
                        //                'summary' => 'I would like to discuss the following...',
                        'start_at' => (string)$startAt,
                        'end_at' => (string)$startAt->copy()->addMinutes(10),
                        'room_id' => $room->getKey(),
                    ]
                ]
            ]
        );

        $response->assertJsonFragment(['reason' => 'Something important'])
            // Here I validated we could create interview w/o summary
            ->assertJsonFragment(['summary' => ''])
            ->assertJsonFragment(['status' => Calendar::STATUS_UNCONFIRMED])
            ->assertJsonFragment(['organizer_user_id' => $this->teacherUser1->getKey()])
            // As the result Student attendee would be resolved to Parents.
            ->assertJsonFragment(['user_id' => $this->parentUser->getKey()])
            ->assertJsonFragment(['user_id' => $this->parentUser2->getKey()])
            ->assertJsonFragment(['attendee_status' => Calendar::STATUS_UNCONFIRMED])
            ->assertJsonFragment(['attendee_status' => Calendar::STATUS_OPTIONAL])
            // My status as Organizer should be confirmed.
            ->assertJsonFragment(['my_status' => Calendar::STATUS_CONFIRMED])

            ->assertJsonFragment(['start_at' => $startAt->toIso8601String()])
            ->assertJsonFragment(['end_at' => $startAt->addMinutes(10)->toIso8601String()])

            ->assertJsonFragment(['name' => 'Room A']);


        // Other Parent can NOT see the Meeting request.
        $parentUser3 = User::factory()->create([
            'name' => 'Parent Z',
            'email' => 'parentZ@gmail.com',
            'school_id' => $this->user->school_id,
        ]);
        $parentUser3->assignRole(Course::ROLE_PARENT);

        $this->actingAs($parentUser3);
        $this->get('/api/meetings/teacher/')
            ->assertJsonFragment(['count' => 0]);

        // Parent can see the Meeting request.
        $this->actingAs($this->parentUser);
        $this->get('/api/meetings/teacher/')
            ->assertJsonFragment(['count' => 1]);
    }

    /**
     * This test will check attendee have Parent role
     */
    public function testTeacherMeetingNotParentRequest()
    {
        $startAt = Carbon::now()->addHour()->startOfHour();

        $this->parentUser->removeRole(Course::ROLE_PARENT);

        $this->actingAs($this->teacherUser1);
        $response = $this->postJson(
            '/api/meetings/teacher',
            [
                'data' => [
                    'attributes' => [
                        'reason' => 'Something important',
                        'attendees' => [['user_id' => $this->parentUser->getKey()]],
                        'summary' => 'I would like to discuss the following...',
                        'start_at' => (string)$startAt,
                    ]
                ]
            ]
        );

        $response->assertJsonFragment(['message' => 'Wrong Attendee'])
//            ->assertJsonFragment(['title' => 'Attendee ' . $this->parentUser->getKey() . ' don\'t have Parent role!'])
            ->assertJsonFragment(['title' => 'Attendee ' . $this->parentUser->getKey() . ' don\'t have Parent role!'])
            ->assertJsonFragment(['code' => ApiCodes::BAD_REQUEST]);
    }

    public function testCalendarTeacherGet()
    {
        $this->actingAs($this->teacherUser1);
        $event = CalendarTeacherParent::factory()->create([
            'school_id' => $this->teacherUser1->school_id,
            'reason' => 'Something important',
            // This should not be applied.
            'organizer_id' => $this->teacherUser1->getKey(),
        ]);
        $response = $this->get('/api/meetings/teacher/' . $event->id);
        $response->assertJsonFragment(['reason' => 'Something important'])
            ->assertJsonFragment(['status' => Calendar::STATUS_UNCONFIRMED])
            ->assertJsonFragment(['organizer_user_id' => $this->teacherUser1->getKey()]);

        $this->teacherUser1->removeRole(Course::ROLE_TEACHER);
        $response = $this->get('/api/meetings/teacher/' . $event->id);
        $response->assertJsonFragment(['message' => 'Unauthorized']);
    }

    public function testCalendarParentGet()
    {
        $event = CalendarTeacherParent::factory()->create([
            'school_id' => $this->teacherUser1->school_id,
            'reason' => 'Something important',
//            'attendees' => [['user_id' => $this->parentUser->getKey()]],
            'organizer_id' => $this->teacherUser1->getKey(),
        ]);
        $event->attendees()->sync([$this->parentUser->getKey()]);

        // Request the event as the attendee.
        $this->actingAs($this->parentUser);
        $response = $this->get('/api/meetings/teacher/' . $event->getKey());
        $response->assertJsonFragment(['reason' => 'Something important'])
            ->assertJsonFragment(['status' => Calendar::STATUS_UNCONFIRMED])
            ->assertJsonFragment(['organizer_user_id' => $this->teacherUser1->getKey()]);

        $this->parentUser->removeRole(Course::ROLE_PARENT);
        $response = $this->get('/api/meetings/teacher/' . $event->getKey());
        $response->assertJsonFragment(['message' => 'Unauthorized']);

        // Try to request the event if the Parent is not an attendee.
        $this->actingAs($this->parentUser2);
        $response = $this->get('/api/meetings/teacher/' . $event->getKey());
        $response->assertJsonFragment(['message' => 'Unauthorized'])
            ->assertJsonFragment(['title' => 'Your request is unauthorized'])
            ->assertJsonFragment(['code' => ApiCodes::UNAUTHORIZED]);
    }
}
