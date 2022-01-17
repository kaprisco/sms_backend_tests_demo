<?php

namespace Tests\Unit;

use App\Models\Calendar;
use App\Models\Calendars\CalendarSimpleEvent;
use App\Models\Course;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\ApiTestCase;

class CalendarModelTest extends ApiTestCase
{
    use RefreshDatabase;

    public function testCalendarModel()
    {
        $this->actingAs($this->teacherUser1);
        $calendar = CalendarSimpleEvent::create($this->teacherUser1, ['summary' => 'Test']);
        $calendar->save();
        $calendar->attendees()->syncWithoutDetaching([
            $this->teacherUser2->getKey() => ['attendee_status' => Calendar::STATUS_CONFIRMED]
        ]);

        $calendar->attendees()->syncWithoutDetaching([$this->parentUser->getKey()]);

        $this->assertCount(2, $calendar->refresh()->attendees);

        $calendar->attendees()->detach([$this->parentUser->getKey()]);
        $this->assertCount(1, $calendar->refresh()->attendees);
    }
}
