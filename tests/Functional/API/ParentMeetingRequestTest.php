<?php

namespace Tests\Functional\API;

use App\Http\ApiCodes;
use App\Models\Alarm;
use App\Models\Calendar;
use App\Models\Calendars\CalendarParentTeacher;
use App\Models\Course;
use Carbon\Carbon;
use Illuminate\Notifications\DatabaseNotification;
use OwenIt\Auditing\Models\Audit;
use Tests\ApiTestCase;

class ParentMeetingRequestTest extends ApiTestCase
{
    public function testUnauthorizedCreate()
    {
        $response = $this->postJson(
            '/api/meetings/parent',
            ['data' => ['attributes' => []]]
        );
        $response->assertJsonFragment(['message' => 'Unauthorized']);
        $response->assertJsonFragment(['code' => ApiCodes::UNAUTHORIZED]);
    }

    public function testModelRequirements()
    {
        $this->user->givePermissionTo(['meeting.parent_teacher.create', 'meeting.parent_teacher.view']);
        $this->actingAs($this->user);
        $response = $this->postJson(
            '/api/meetings/parent',
            ['data' => ['attributes' => []]]
        );
        $response->assertJsonFragment(['title' => 'Failed model validation'])
            ->assertJsonFragment([
                'message' => 'data.attributes.reason: The data.attributes.reason field is required.'
            ])
            ->assertJsonFragment([
                'message' => 'data.attributes.start_at: The data.attributes.start at field is required.'
            ]);
    }


    public function testTeacherReject()
    {
        $startAt = Carbon::now()->addHour()->startOfHour();

        $meeting = CalendarParentTeacher::factory()->create([
            'organizer_id' => $this->parentUser->getKey(),
            'school_id' => $this->user->school_id,
            'reason' => 'Something important',
            'summary' => 'I would like to discuss the following...',
            'start_at' => (string)$startAt,
            'end_at' => $startAt->addMinutes(30),
            'updated_at' => now()->subYear(),
        ]);
        $meeting->attendees()->sync([$this->teacherUser1->getKey()]);

        $this->actingAs($this->teacherUser1);

        $this->patchJson(
            '/api/calendars/' . $meeting->getKey() . '/change_status',
            [
                'data' => [
                    'attributes' => [
                        'status' => Calendar::STATUS_REJECTED,
                    ]
                ]
            ]
        )->assertJsonFragment(['attendee_status' => Calendar::STATUS_REJECTED])
            // Calendar Event should be properly cast.
            ->assertJsonFragment(['type' => 'parent_teacher']);
    }

    public function testTeacherConfirmation()
    {
        $startAt = Carbon::now()->addHour()->startOfHour();

        $meeting = CalendarParentTeacher::factory()->create([
            'organizer_id' => $this->parentUser->getKey(),
            'school_id' => $this->user->school_id,
            'reason' => 'Something important',
            'summary' => 'I would like to discuss the following...',
            'start_at' => (string)$startAt,
            'end_at' => $startAt->addMinutes(30),
            // This will allow the model to be touched, and it will fire update event.
            'updated_at' => now()->subYear(),
        ]);
        $meeting->attendees()->sync([$this->teacherUser1->getKey()]);
        $this->actingAs($this->teacherUser1);

        $response = $this->patchJson(
            '/api/calendars/' . $meeting->getKey() . '/change_status',
            [
                'data' => [
                    'attributes' => [
                        'status' => Calendar::STATUS_CONFIRMED,
                    ]
                ]
            ]
        );
        $response->assertJsonFragment(['attendee_status' => Calendar::STATUS_CONFIRMED])
            ->assertJsonFragment(['status' => CalendarParentTeacher::STATUS_WAITING_MANAGER_CONFIRM]);

        // As a teacher I shouldn't be able to view confirmation endpoint contents.
        $response = $this->get('/api/meetings/parent/confirm');
        $response->assertJsonFragment(['message' => 'Unauthorized'])
            ->assertJsonFragment(['title' => 'Your request is unauthorized'])
            ->assertJsonFragment(['code' => ApiCodes::UNAUTHORIZED]);

        $this->actingAs($this->supervisorUser);
        // Now we should see a list on unconfirmed meetings
        $response = $this->get('/api/meetings/parent/confirm');
        $response->assertJsonFragment(['status' => CalendarParentTeacher::STATUS_WAITING_MANAGER_CONFIRM]);

        $response = $this->get('/api/meetings/parent/confirm/666')
            ->assertJsonFragment(['code' => ApiCodes::ENDPOINT_NOT_FOUND]);

        $response = $this->get('/api/meetings/parent/confirm/' . $meeting->getKey());
        $response->assertJsonFragment(['status' => CalendarParentTeacher::STATUS_WAITING_MANAGER_CONFIRM]);

        // Finally, the event should be confirmed by Supervisor.
        $response = $this->patchJson(
            '/api/meetings/parent/confirm/' . $meeting->getKey(),
            [
                'data' => [
                    'attributes' => [
                        'status' => Calendar::STATUS_CONFIRMED,
                    ]
                ]
            ]
        );
        $response->assertJsonFragment(['status' => Calendar::STATUS_CONFIRMED]);

        // There should be 3 audit records for the meeting item.
        $this->assertCount(3, $meeting->audits);
    }

    /**
     * Parent requests a meeting.
     *
     * @return void
     * @throws \Throwable
     */
    public function testParentMeetingRequest()
    {
        $startAt = Carbon::now()->addHour()->startOfHour();

        $this->actingAs($this->parentUser);
        $response = $this->postJson(
            '/api/meetings/parent',
            [
                'data' => [
                    'attributes' => [
                        'reason' => 'Something important',
                        'attendees' => [['user_id' => $this->teacherUser1->getKey()]],
                        'summary' => 'I would like to discuss the following...',
                        // Should not be taken into consideration.
                        'organizer_id' => $this->teacherUser1->getKey(),
                        'start_at' => (string)$startAt,
                    ]
                ]
            ]
        );

        $response->assertJsonFragment(['reason' => 'Something important'])
            ->assertJsonFragment(['summary' => 'I would like to discuss the following...'])
            ->assertJsonFragment(['status' => Calendar::STATUS_UNCONFIRMED])
            ->assertJsonFragment(['organizer_user_id' => $this->parentUser->getKey()])
            ->assertJsonFragment(['user_id' => $this->teacherUser1->getKey()])
            ->assertJsonFragment(["attendee_status" => Calendar::STATUS_UNCONFIRMED])
            ->assertJsonFragment(['start_at' => $startAt->toIso8601String()])
            ->assertJsonFragment(['end_at' => $startAt->addMinutes(30)->toIso8601String()]);

        // There should be two notifications, one for organizer, the second for attendee.
        $this->assertCount(2, DatabaseNotification::all());
    }

    /**
     * This test will check attendee have Teacher role
     */
    public function testParentMeetingNotTeacherRequest()
    {
        $startAt = Carbon::now()->addHour()->startOfHour();

        $this->teacherUser1->removeRole(Course::ROLE_TEACHER);

        $this->actingAs($this->parentUser);
        $response = $this->postJson(
            '/api/meetings/parent',
            [
                'data' => [
                    'attributes' => [
                        'reason' => 'Something important',
                        'attendees' => [['user_id' => $this->teacherUser1->getKey()]],
                        'summary' => 'I would like to discuss the following...',
                        'start_at' => (string)$startAt,
                    ]
                ]
            ]
        );

        $response->assertJsonFragment(['message' => 'Wrong Attendee'])
            ->assertJsonFragment(['title' => 'Attendee ' . $this->teacherUser1->getKey() . ' is not a teacher!'])
            ->assertJsonFragment(['code' => ApiCodes::BAD_REQUEST]);
    }

    public function testCalendarParentGet()
    {
        $this->actingAs($this->parentUser);
        $event = CalendarParentTeacher::factory()->create([
            'kind' => CalendarParentTeacher::$kind,
            'school_id' => $this->user->school_id,
            'reason' => 'Something important',
            // This should not be applied.
            'organizer_id' => $this->parentUser->getKey(),
        ]);

        $response = $this->get('/api/meetings/parent/' . $event->id);
        $response->assertJsonFragment(['reason' => 'Something important'])
            ->assertJsonFragment(['status' => Calendar::STATUS_UNCONFIRMED])
            ->assertJsonFragment(['organizer_user_id' => $this->parentUser->getKey()]);

        $this->parentUser->removeRole(Course::ROLE_PARENT);

        $response = $this->get('/api/meetings/parent/' . $event->id);
        $response->assertJsonFragment(['message' => 'Unauthorized']);
    }

    public function testCalendarTeacherGet()
    {
        $event = CalendarParentTeacher::factory()->create([
            'kind' => CalendarParentTeacher::$kind,
            'school_id' => $this->user->school_id,
            'reason' => 'Something important',
            'organizer_id' => $this->parentUser->getKey(),
        ]);
        $event->attendees()->sync([$this->teacherUser1->getKey()]);

        // Request the event as the attendee.
        $this->actingAs($this->teacherUser1);
        $response = $this->get('/api/meetings/parent/' . $event->getKey());
        $response->assertJsonFragment(['reason' => 'Something important'])
            ->assertJsonFragment(['status' => Calendar::STATUS_UNCONFIRMED])
            ->assertJsonFragment(['organizer_user_id' => $this->parentUser->getKey()]);

        // Try to request the event if the Teacher is not an attendee.
        $this->actingAs($this->teacherUser2);
        $response = $this->get('/api/meetings/parent/' . $event->getKey());
        $response->assertJsonFragment(['message' => 'Unauthorized'])
            ->assertJsonFragment(['title' => 'Your request is unauthorized'])
            ->assertJsonFragment(['code' => ApiCodes::UNAUTHORIZED]);

        $this->teacherUser1->removeRole(Course::ROLE_TEACHER);
        $response = $this->get('/api/meetings/parent/' . $event->getKey());
        $response->assertJsonFragment(['message' => 'Unauthorized']);
    }
}
