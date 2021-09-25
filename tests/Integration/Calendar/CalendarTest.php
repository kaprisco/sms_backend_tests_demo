<?php

namespace Tests\Integration\Calendar;

use Carbon\Carbon;
use Spatie\GoogleCalendar\Event;
use Tests\FunctionalTestCase;

class CalendarTest extends FunctionalTestCase
{

    public function setUp(): void
    {
        parent::setUp();
        $this->cleanupEvents();
    }

    public function cleanupEvents()
    {
        /** @var Event[] $events */
        $events = Event::get();
        foreach ($events as $event) {
            dd($event);
            $event->delete();
        }
    }

    public function testEventCreate()
    {
        $event = new Event();
        $event->name = 'NEW Test Event ' . rand(100, 999);
        $event->startDateTime = Carbon::now()->addHour()->startOfHour();
        $event->endDateTime = Carbon::now()->addHours(2)->startOfHour();
        $event->description = "Kawabonga!\n<b>Is bolda!</b>";
        $event->addAttendee(['hlorofos@gmail.com']);
        $event->save();

        $this->assertNotNull($event);
    }

    public function testEventChange()
    {
        // This will validate the event details could be changed.
        $event = Event::get()->first();
        $this->assertNotNull($event);

        $event->name = 'CONFIRMED Test Event ' . rand(100, 999);
        $event->colorId = 10;
        $event->description = "Kawabonga!\n<b>Is bolda!</b>\nCONFIRMED!";
        $event->save();
    }
}
