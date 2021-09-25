<?php

namespace Tests\Integration\Calendar;

use App\Mail\CalendarInvitation;
use App\Mail\IcsContents;
use Carbon\Carbon;
use Illuminate\Support\Facades\Mail;
use Spatie\CalendarLinks\Link;
use Tests\FunctionalTestCase;

class CalendarInvitationTest extends FunctionalTestCase
{

    public function testInvitationCreate()
    {
        $link = Link::create(
            'Test Event ' . rand(100, 999),
            Carbon::now('GMT+3')->addHour()->startOfHour(),
            Carbon::now('GMT+3')->addHours(2)->startOfHour()
        )->description("Kawabonga!\n<b>Is bold!</b>");
        $this->assertNotNull($link);

        Mail::to('hlorofos@gmail.com')->send(new CalendarInvitation($link));

        dump($link->google());
        dump($link->formatWith(new IcsContents));
    }
}
