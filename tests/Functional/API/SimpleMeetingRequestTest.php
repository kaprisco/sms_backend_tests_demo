<?php

namespace Tests\Functional\API;

use App\Http\ApiCodes;
use App\Models\Calendar;
use App\Models\Calendars\CalendarSimpleEvent;
use App\Models\Room;
use Illuminate\Notifications\DatabaseNotification;
use Tests\ApiTestCase;

class SimpleMeetingRequestTest extends ApiTestCase
{
    public function testUnauthorizedCreate()
    {
        $response = $this->postJson(
            '/api/calendars/',
            [
                'data' => [
                    'attributes' => [
                        'reason' => 'Simple Event',
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
        $this->user->givePermissionTo(['calendar.create']);
        $this->actingAs($this->user);
        $response = $this->postJson(
            '/api/calendars/',
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

    public function testCreateSimpleEvent()
    {
        /** @var Room $room */
        $room = Room::factory(['name' => 'Room A'])->create();

        $response = $this->actingAs($this->teacherUser1)->postJson(
            '/api/calendars/?include=room',
            [
                'data' => [
                    'attributes' => [
                        'reason' => 'Simple Event',
                        'summary' => 'Test summary',
                        'start_at' => now()->startOfHour(),
                        'end_at' => now()->endOfHour(),
                        'attendees' => [
                            ['user_id' => $this->parentUser->getKey()],
                            ['user_id' => $this->parentUser2->getKey()]
                        ],
                        'room_id' => $room->getKey(),
                    ]
                ]
            ]
        );

        $response->assertJsonFragment(['summary' => 'Test summary'])
            ->assertJsonFragment(['reason' => 'Simple Event'])
            // Room should be attached.
            ->assertJsonFragment(['name' => 'Room A'])
            ->assertJsonFragment(['type' => 'simple'])
            ->assertJsonFragment(['user_id' => $this->parentUser->getKey()])
            ->assertJsonFragment(['user_id' => $this->parentUser2->getKey()]);
        $meetingId = $response->decodeResponseJson()['data']['id'];

        // Participants could change their own status.
        $this->actingAs($this->parentUser)->patchJson(
            '/api/calendars/'. $meetingId . '/change_status',
            [
                'data' => [
                    'attributes' => [
                        'status' => Calendar::STATUS_REJECTED,
                    ]
                ]
            ]
        )->assertJsonFragment(['my_status' => Calendar::STATUS_REJECTED]);

        // Include should work properly even there is no Course attached to the Simple Event.
        $this->get("/api/calendars?include=course")
            ->assertJsonFragment(['total' => 1]);

        // There should be two notifications, for attendees only.
        $this->assertCount(2, DatabaseNotification::all());

        $this->actingAs($this->parentUser)
            ->get("/api/notifications?type=interview_request,simple&" .
                "include=user:fields(name|last_name),user.roles:fields(name),calendar:fields(my_status)")
            ->assertJsonFragment(['title' => 'Event created by Teacher A <teacherA@gmail.com>'])
            ->assertJsonFragment(['total' => 1])
            // Organizer name included
            ->assertJsonFragment(['name' => 'Teacher A'])
            // Role included.
            ->assertJsonFragment(['name' => 'teacher'])
            // Attendee status included
            ->assertJsonFragment(['my_status' => Calendar::STATUS_REJECTED])
            ->assertJsonFragment(['type' => 'simple']);

        $event = CalendarSimpleEvent::first();

        $this->actingAs($this->teacherUser1)->delete('/api/calendars/' . $event->getKey())
            ->assertSuccessful();

        // After event deletion the notification should gone, since there is no more sense in the notification.
        $this->actingAs($this->parentUser)
            ->get("/api/notifications")
            ->assertJsonFragment(['total' => 0]);
    }

    public function testUpdateSimpleEvent()
    {
        $meeting = CalendarSimpleEvent::factory()->create([
            'organizer_id' => $this->teacherUser1->getKey(),
            'school_id' => $this->teacherUser1->school_id,
            'reason' => 'Something important',
            'start_at' => now()->startOfHour(),
            'end_at' => now()->endOfHour(),
            'updated_at' => now()->subYear(),
        ]);
        $meeting->attendees()->sync([$this->teacherUser2->getKey()]);

        // Primary teacher can change the attendee.
        $this->patchJson(
            '/api/calendars/' . $meeting->getKey() . '?include=users',
            [
                'data' => [
                    'attributes' => [
                        'attendees' => [
                            ['user_id' => $this->parentUser->getKey(),]
                        ],
                    ]
                ]
            ]
        )->assertJsonFragment(['user_id' => $this->parentUser->getKey()]);

        // Primary teacher can change the attendee.
        $this->patchJson(
            '/api/calendars/' . $meeting->getKey() . '?include=users',
            [
                'data' => [
                    'attributes' => [
                        'attendees' => [
                            ['user_id' => $this->parentUser2->getKey(),]
                        ],
                    ]
                ]
            ]
        )->assertJsonFragment(['user_id' => $this->parentUser2->getKey()])
            ->assertDontSeeText($this->parentUser->getKey())
            ->assertDontSeeText($this->teacherUser2->getKey());
    }
}
