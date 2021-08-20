<?php

namespace Tests\Functional\API;

use App\Http\ApiCodes;
use App\Models\Calendar;
use App\Models\Calendars\CalendarParentTeacher;
use App\Models\Calendars\CalendarTeacherParent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\ApiTestCase;
use Tests\Traits\RefreshAndKeepDatabase;

class CalendarIndexTest extends ApiTestCase
{
    use RefreshAndKeepDatabase;
    public function setUp(): void
    {
        parent::setUp();

        CalendarTeacherParent::factory()->create([
            'organizer_id' => $this->teacherUser1->getKey(),
            'school_id' => $this->teacherUser1->school_id,
            'reason' => 'Something important',
            'summary' => 'I would like to discuss the following...',
            'attendees' => [
                ['user_id' => $this->parentUser->getKey()],
                ['user_id' => $this->parentUser2->getKey()]
            ],
        ]);
        CalendarParentTeacher::factory()->create([
            'organizer_id' => $this->parentUser->getKey(),
            'school_id' => $this->user->school_id,
            'reason' => 'Something important',
            'summary' => 'Something to discuss...',
            'attendees' => [['user_id' => $this->teacherUser1->getKey()]],
        ]);

        // This event should be visible only to admin, we'll request from parentUser and teacherUser1
        CalendarParentTeacher::factory()->create([
            'organizer_id' => $this->parentUser->getKey(),
            'school_id' => $this->user->school_id,
            'reason' => 'Not seen',
            'summary' => 'Not seen...',
            'attendees' => [['user_id' => $this->teacherUser2->getKey()]],
        ]);
    }

    public function testCalendarIndex()
    {
        $this->get('/api/calendars')
            ->assertJsonFragment(['message' => 'Unauthorized'])
            ->assertJsonFragment(['code' => ApiCodes::UNAUTHORIZED]);

        $this->user->givePermissionTo(['calendar.index', 'calendar.view']);

        // Admin user requesting calendar list here, so both events would be returned back.
        $this->get('/api/calendars')
            // Admin should see all the events
            ->assertJsonFragment(['total' => 3])
            ->assertJsonFragment(['attendee_status' => Calendar::STATUS_UNCONFIRMED])
            ->assertJsonFragment(['status' => CalendarTeacherParent::STATUS_UNCONFIRMED]);
    }

    public function testCalendarAudits()
    {
        $this->user->givePermissionTo(['audit.view', 'calendar.index', 'calendar.view']);

        // Admin user requesting calendar list here, so both events would be returned back.
        $this->get('/api/calendars?include=audit')
            ->assertJsonFragment(['total' => 3])
            ->assertJsonFragment(['attendee_status' => Calendar::STATUS_UNCONFIRMED])
            ->assertJsonFragment(['status' => CalendarTeacherParent::STATUS_UNCONFIRMED]);
    }

    public function testCalendarOrganizerIndex()
    {
        $this->actingAs($this->teacherUser1);
        $this->teacherUser1->givePermissionTo(['calendar.index', 'calendar.view']);

        // Organizers could see their own events.
        // And the events they are attendee of.
        $this->get('/api/calendars')
            ->assertJsonFragment(['total' => 2])
            ->assertJsonFragment(['attendee_status' => Calendar::STATUS_UNCONFIRMED])
            ->assertJsonFragment(['status' => CalendarTeacherParent::STATUS_UNCONFIRMED])
            ->assertJsonFragment(['organizer_user_id' => $this->teacherUser1->getKey()])
            ->assertJsonFragment(['user_id' => $this->teacherUser1->getKey()]);
    }

    public function testCalendarAttendeeIndex()
    {
        // This will delete event where parentUser is the organizer.
        foreach (CalendarParentTeacher::all() as $item) {
            $item->delete();
        }

        $this->actingAs($this->parentUser);
        $this->parentUser->givePermissionTo(['calendar.index', 'calendar.view']);

        // Attendees could see events they participate in.
        $this->get('/api/calendars')
            ->assertJsonFragment(['total' => 1])
            ->assertJsonFragment(['attendee_status' => Calendar::STATUS_UNCONFIRMED])
            ->assertJsonFragment(['status' => CalendarTeacherParent::STATUS_UNCONFIRMED])
            // Ensure we see attendee user_id
            ->assertJsonFragment(['user_id' => $this->parentUser->getKey()])
            // Parent here is the organizer
            ->assertJsonFragment(['kind' => 'App\\Models\\Calendars\\CalendarTeacherParent']);
    }
}
