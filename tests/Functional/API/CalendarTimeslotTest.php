<?php

namespace Tests\Functional\API;

use App\Http\ApiCodes;
use App\Models\Calendar;
use App\Models\Calendars\CalendarSimpleEvent;
use App\Models\Calendars\CalendarTimeslotEvent;
use App\Models\Course;
use App\Models\Room;
use App\Models\User;
use Tests\ApiTestCase;

class CalendarTimeslotTest extends ApiTestCase
{
    public function testUnauthorizedCreate()
    {
        $response = $this->postJson(
            '/api/calendars/timeslot',
            [
                'data' => [
                    'attributes' => [
                        'reason' => 'Timeslot Event',
                        'start_at' => now()->startOfHour(),
                        'end_at' => now()->endOfHour(),
                    ]
                ]
            ]
        );
        $response->assertJsonFragment(['message' => 'Unauthorized']);
        $response->assertJsonFragment(['code' => ApiCodes::UNAUTHORIZED]);
    }

    public function testModelRequirements()
    {
        $this->user->givePermissionTo(['calendar.timeslot.create']);
        $this->actingAs($this->user);
        $response = $this->postJson(
            '/api/calendars/timeslot',
            ['data' => ['attributes' => []]]
        );
        $response->assertJsonFragment(['title' => 'Failed model validation'])
            ->assertDontSeeText('data.attributes.reason')
            ->assertJsonFragment([
                'message' => 'data.attributes.start_at: The data.attributes.start at field is required.'
            ]);
    }

    public function testDeleteTimeslotEvent()
    {
        $timeSlot = CalendarTimeslotEvent::create(
            $this->user,
            [
                'start_at' => '2000-01-01 00:00:00',
                'end_at' => '2000-01-01 01:00:00',
            ]
        );

        $this->delete('/api/calendars/'. $timeSlot->getKey())
            ->assertJsonFragment(['title' => 'You do not have the permission to delete timeslot events!']);

        $this->user->givePermissionTo(['calendar.timeslot.delete']);

        $this->delete('/api/calendars/'. $timeSlot->getKey())->assertSuccessful();
        $this->assertCount(0, Calendar::all());
    }

    public function testUpdateTimeslotEvent()
    {
        $timeSlot = CalendarTimeslotEvent::create(
            $this->user,
            [
                'start_at' => '2000-01-01 00:00:00',
                'end_at' => '2000-01-01 01:00:00',
            ]
        );

        $this->user->givePermissionTo(['calendar.view', 'calendar.index']);

        $response = $this->get('/api/calendars')
            ->assertJsonFragment(['start_at' => '2000-01-01T00:00:00+00:00'])
            ->assertJsonFragment(['end_at' => '2000-01-01T01:00:00+00:00']);

        $updateTimePayload = [
            'data' => [
                'attributes' => [
                    'start_at' => '2002-01-01T00:00:00+00:00',
                    'end_at' => '2002-01-01T01:00:00+00:00',
                ]
            ]
        ];

        // Non-organizer cannot edit TimeSlot Event.
        $this->actingAs($this->teacherUser2)->patchJson(
            '/api/calendars/' . $timeSlot->getKey(),
            $updateTimePayload
        )->assertJsonFragment(['title' => 'Your calendar time update request for foreign event is unauthorized']);

        // There is no proper permission
        $this->actingAs($this->user)->patchJson(
            '/api/calendars/' . $timeSlot->getKey(),
            $updateTimePayload
        )->assertJsonFragment(['title' => 'Your calendar time update request is unauthorized']);

        $this->user->givePermissionTo(['calendar.update']);

        $this->actingAs($this->user)->patchJson(
            '/api/calendars/' . $timeSlot->getKey(),
            $updateTimePayload
        )->assertJsonFragment(['start_at' => '2002-01-01T00:00:00+00:00'])
            ->assertJsonFragment(['end_at' => '2002-01-01T01:00:00+00:00']);
    }

    public function testCreateTimeslotEvent()
    {
        $this->actingAs($this->teacherUser1);

//        $this->user->givePermissionTo(['calendar.timeslot.create','calendar.view', 'calendar.index']);
        /** @var Room $room */
        $room = Room::factory(['name' => 'Room A'])->create();

        $response = $this->postJson(
            '/api/calendars/timeslot?include=room',
            [
                'data' => [
                    'attributes' => [
                        'start_at' => now()->startOfHour(),
                        'end_at' => now()->endOfHour(),
                        'room_id' => $room->getKey(),
                        'location' => [
                            'type' => 'location',
                            'location_room' => '303',
                        ]
                    ]
                ]
            ]
        );
        $response->assertJsonFragment(['start_at' => now()->startOfHour()->toIso8601String()])
            ->assertJsonFragment(['end_at' => now()->endOfHour()->toIso8601String()])
            ->assertJsonFragment(['location_room' => '303'])
            ->assertJsonFragment(['name' => 'Room A'])
            ->assertJsonFragment(['status' => CalendarTimeslotEvent::STATUS_AVAILABLE]);

        CalendarSimpleEvent::factory([
            'school_id' => $this->teacherUser1->school_id,
            'organizer_id' => $this->teacherUser1->getKey()
        ])->create();

        // Include should work properly even there is no Course attached to the Simple Event.
        $this->get("/api/calendars?include=course")
            ->assertJsonFragment(['total' => 2]);
        // Filter Calendar Events by kind
        $this->get("/api/calendars?kind=App\\Models\\Calendars\\CalendarTimeslotEvent")
            ->assertJsonFragment(['total' => 1])
            // Pagination meta should include query parameters.
            ->assertSeeText('kind=');
    }
}
