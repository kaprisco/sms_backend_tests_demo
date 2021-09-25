<?php

namespace Tests\Functional\API;

use App\Http\ApiCodes;
use App\Models\Calendar;
use App\Models\Calendars\CalendarTeacherParent;
use App\Models\Course;
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
                'message' => 'data.attributes.reason: The data.attributes.reason field is required.'])
            ->assertJsonFragment([
                'message' => 'data.attributes.start_at: The data.attributes.start at field is required.'
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
            'attendees' => [['user_id' => $this->parentUser->getKey()]],
            'start_at' => (string)$startAt,
            'end_at' => $startAt->addMinutes(30),
        ]);

        $this->actingAs($this->parentUser);

        $response = $this->patchJson(
            '/api/meetings/teacher/' . $meeting->getKey() . '/change_status',
            ['data' => ['attributes' => [
                'status' => Calendar::STATUS_REJECTED,
            ]]]
        );
        $response->assertJsonFragment(['attendee_status' => Calendar::STATUS_REJECTED]);


        $this->parentUser->removeRole('Parent');
        $response = $this->patchJson(
            '/api/meetings/teacher/' . $meeting->getKey() . '/change_status',
            ['data' => ['attributes' => [
                'status' => Calendar::STATUS_REJECTED,
            ]]]
        );
        $response->assertJsonFragment(['message' => 'Unauthorized']);
    }

    public function testParentConfirmation()
    {
        $startAt = Carbon::now()->addHour()->startOfHour();

        $meeting = CalendarTeacherParent::factory()->create([
            'organizer_id' => $this->teacherUser1->getKey(),
            'school_id' => $this->teacherUser1->school_id,
            'reason' => 'Something important',
            'summary' => 'I would like to discuss the following...',
            'attendees' => [['user_id' => $this->parentUser->getKey()]],
            'start_at' => (string)$startAt,
            'end_at' => $startAt->addMinutes(30),
        ]);

        $this->actingAs($this->parentUser);

        $response = $this->patchJson(
            '/api/meetings/teacher/' . $meeting->getKey() . '/change_status',
            ['data' => ['attributes' => [
                'status' => Calendar::STATUS_CONFIRMED,
            ]]]
        );
        $response->assertJsonFragment(['attendee_status' => Calendar::STATUS_CONFIRMED])
            ->assertJsonFragment(['status' => CalendarTeacherParent::STATUS_CONFIRMED]);

        $this->parentUser->removeRole('Parent');

        $response = $this->patchJson(
            '/api/meetings/teacher/' . $meeting->getKey() . '/change_status',
            ['data' => ['attributes' => [
                'status' => Calendar::STATUS_CONFIRMED,
            ]]]
        );
        $response->assertJsonFragment(['message' => 'Unauthorized']);

        // There should be 2 audit records for the meeting item.
        $this->assertCount(2, $meeting->audits);
    }

    /**
     * Teacher requests a meeting.
     *
     * @return void
     * @throws \Throwable
     */
    public function testTeacherMeetingRequest()
    {
        $startAt = Carbon::now()->addHour()->startOfHour();

        $this->actingAs($this->teacherUser1);
        $response = $this->postJson(
            '/api/meetings/teacher',
            ['data' => ['attributes' => [
                'reason' => 'Something important',
                'attendees' => [['user_id' => $this->parentUser->getKey()]],
                'summary' => 'I would like to discuss the following...',
                'organizer_id' => $this->teacherUser1->getKey(),
                'start_at' => (string)$startAt,
            ]]]
        );

        $response->assertJsonFragment(['reason' => 'Something important'])
            ->assertJsonFragment(['summary' => 'I would like to discuss the following...'])
            ->assertJsonFragment(['status' => Calendar::STATUS_UNCONFIRMED])
            ->assertJsonFragment(['organizer_user_id' => $this->teacherUser1->getKey()])
            ->assertJsonFragment(['attendees' => [
                [
                    'user_id' => $this->parentUser->getKey(),
                    "attendee_status" => Calendar::STATUS_UNCONFIRMED
                ]
            ]])
            ->assertJsonFragment(['start_at' => $startAt->toIso8601String()])
            ->assertJsonFragment(['end_at' => $startAt->addMinutes(30)->toIso8601String()]);
    }

    /**
     * This test will check attendee have Parent role
     */
    public function testTeacherMeetingNotParentRequest()
    {
        $startAt = Carbon::now()->addHour()->startOfHour();

        $this->parentUser->removeRole('Parent');

        $this->actingAs($this->teacherUser1);
        $response = $this->postJson(
            '/api/meetings/teacher',
            ['data' => ['attributes' => [
                'reason' => 'Something important',
                'attendees' => [['user_id' => $this->parentUser->getKey()]],
                'summary' => 'I would like to discuss the following...',
                'start_at' => (string)$startAt,
            ]]]
        );

        $response->assertJsonFragment(['message' => 'Wrong Attendee'])
            ->assertJsonFragment(['title' => 'Attendee ' . $this->parentUser->getKey() . ' is not a parent!'])
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
            'attendees' => [['user_id' => $this->parentUser->getKey()]],
            'organizer_id' => $this->teacherUser1->getKey(),
        ]);

        // Request the event as the attendee.
        $this->actingAs($this->parentUser);
        $response = $this->get('/api/meetings/teacher/' . $event->getKey());
        $response->assertJsonFragment(['reason' => 'Something important'])
            ->assertJsonFragment(['status' => Calendar::STATUS_UNCONFIRMED])
            ->assertJsonFragment(['organizer_user_id' => $this->teacherUser1->getKey()]);

        $this->parentUser->removeRole('Parent');
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
