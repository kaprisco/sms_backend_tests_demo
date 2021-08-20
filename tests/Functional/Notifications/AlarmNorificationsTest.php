<?php

namespace Tests\Functional\Notifications;

use App\Http\ApiCodes;
use App\Http\Controllers\API\Transformers\AlarmTransformer;
use App\Models\Alarm;
use App\Models\School;
use App\Models\User;
use Illuminate\Http\Response;
use Illuminate\Notifications\DatabaseNotification;
use Spatie\Permission\Models\Permission;
use Tests\ApiTestCase;

class AlarmNorificationsTest extends ApiTestCase
{
    public function setUp(): void
    {
        parent::setUp();
        Alarm::truncate();
    }

    /**
     * Here we test notifications about new Alarm.
     */
    public function testAlarmTriggeredNotifications()
    {
        Alarm::initiate($this->teacherUser1, 'reason1');
        // User who initiated the Alarm would not get the notification
        $this->assertEquals(0, $this->teacherUser1->notifications->count());

        // Other Teachers would get the notification.
        /** @var DatabaseNotification $notification */
        $notification = $this->teacherUser2->notifications->first();
        $this->assertEquals('App\Notifications\AlarmTriggeredNotification', $notification->type);

        $this->assertEquals($this->teacherUser1->getKey(), $notification->data['alarm']['user_id']);
        $this->assertEquals('reason1', $notification->data['alarm']['reason']);
    }
}
