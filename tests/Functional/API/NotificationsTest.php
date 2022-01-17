<?php

namespace Tests\Functional\API;

use App\Models\Alarm;
use App\Models\Calendars\CalendarParentTeacher;
use App\Models\Calendars\CalendarTeacherParent;
use App\Models\Course;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Response;
use Illuminate\Notifications\DatabaseNotification;
use Tests\ApiTestCase;

class NotificationsTest extends ApiTestCase
{
    public function setUp(): void
    {
        parent::setUp();

        Alarm::initiate($this->user, 'reason1');
        Alarm::initiate($this->user, 'reason2');
    }

    /**
     * Get a list of notifications.
     */
    public function testNotificationIndex()
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

        $this->actingAs($this->teacherUser1)->get('/api/notifications')
            ->assertJsonFragment(['total' => 3])
            ->assertJsonFragment(['unread' => 3])
            ->assertJsonFragment(["title" => "Alarm triggered by Tester <tester@gmail.com>"])
            ->assertJsonFragment(["description" => "Reason is: reason2"])
            ->assertJsonFragment(["description" => "Reason is: reason1"])
            ->assertJsonFragment(["type" => "announcement"])
            // Also we would see interview request notification
            ->assertJsonFragment(["type" => "interview_request"])
            ->assertJsonFragment(["description" => "Reason is: Something important"])

            // There should be entities referred.
            ->assertJsonFragment(["entity_type" => "parent_teacher"])
            ->assertJsonFragment(["entity_type" => "Alarm"])

            ->assertJsonFragment(["description" => "Reason is: Something important"]);

        // Filters tests.
        $this->actingAs($this->teacherUser1)->get('/api/notifications?type=interview_request')
            ->assertJsonFragment(['total' => 1]);

        $this->actingAs($this->teacherUser1)->get('/api/notifications?q=reason1')
            ->assertJsonFragment(['total' => 1])
            ->assertJsonFragment(["description" => "Reason is: reason1"]);

        $this->actingAs($this->teacherUser1)->get('/api/notifications?q=something')
            ->assertJsonFragment(['total' => 1])
            ->assertJsonFragment(["description" => "Reason is: Something important"]);

        $this->actingAs($this->teacherUser1)->get('/api/notifications?'.
            'include=user:fields(name|profile_photo_url),user.roles:fields(name)')
            ->assertJsonFragment(['total' => 3])
            // Admin user who initiated the Alarm should be included in the result.
            ->assertJsonFragment(['name' => $this->user->name])
            ->assertJsonFragment(['profile_photo_url' => $this->user->profile_photo_url])
            // Parent A who initiated parent->Teacher meeting should be included.
            ->assertJsonFragment(['name' => $this->parentUser->name])
            ->assertJsonFragment(['profile_photo_url' => $this->parentUser->profile_photo_url])
            // Included user.roles
            ->assertJsonFragment(['name' => Course::ROLE_PARENT]);

        // Teacher is also getting the notification and could see Parent A as the originator.
        $this->actingAs($this->teacherUser1)
            ->get('/api/notifications?include=user:fields(name|profile_photo_url),calendar')
            ->assertJsonFragment(['name' => $this->parentUser->name]);
    }

    /**
     * Get the specific notification and test we can't retrieve someone else notification.
     */
    public function testGetNotification()
    {
        $notification = $this->teacherUser2->notifications->first();
        $this->actingAs($this->teacherUser2)->get('/api/notifications/' . $notification->getKey())
            ->assertJsonFragment(["title" => "Alarm triggered by Tester <tester@gmail.com>"])
            ->assertJsonFragment(["description" => "Reason is: " . $notification->data['alarm']['reason']]);

        // Get someone else notification
        $notification = $this->teacherUser1->notifications->first();
        $this->actingAs($this->teacherUser2)->get('/api/notifications/' . $notification->getKey())
            // should not return the content.
            ->assertStatus(Response::HTTP_UNAUTHORIZED)
            ->assertJsonFragment(["message" => "Unauthorized"])
            ->assertJsonFragment(["title" => "Your request is unauthorized"]);
    }

    public function testReadAllNotification()
    {
        /** @var DatabaseNotification $notification */
        $notification = $this->teacherUser2->notifications->first();
        $this->assertFalse($notification->read());

        $notification2 = $this->teacherUser2->notifications->last();
        $this->assertFalse($notification2->read());

        // Mark notification as read.
        $this->actingAs($this->teacherUser2)->post(
            '/api/notifications/read_all'
        )
            ->assertSuccessful();
        $this->assertTrue($notification->refresh()->read());
        $this->assertTrue($notification2->refresh()->read());

        // Other user's notification should not be touched.
        $notification = $this->teacherUser1->notifications->first();
        $this->assertFalse($notification->read());
    }

    /**
     * Mark notification read/unread and ensure we can't touch someone else notification.
     */
    public function testReadUnreadNotification()
    {
        /** @var DatabaseNotification $notification */
        $notification = $this->teacherUser2->notifications->first();
        $this->assertFalse($notification->read());

        // Mark notification as read.
        $this->actingAs($this->teacherUser2)->patchJson(
            '/api/notifications/' . $notification->getKey(),
            ['data' => ['attributes' => ['read_at' => now()]]]
        )
            ->assertJsonFragment(['type' => 'notification'])
            ->assertSuccessful();
        $this->assertTrue($notification->refresh()->read());

        // Mark notification as unread.
        $this->actingAs($this->teacherUser2)->patchJson(
            '/api/notifications/' . $notification->getKey(),
            ['data' => ['attributes' => ['read_at' => null]]]
        )->assertJsonFragment(['type' => 'notification'])
            ->assertSuccessful()
            ->assertJsonFragment(['read_at' => null]);

        $this->assertFalse($notification->refresh()->read());

        // Try to edit someone else notification
        $notification = $this->teacherUser1->notifications->first();
        $this->actingAs($this->teacherUser2)->patchJson(
            '/api/notifications/' . $notification->getKey(),
            ['data' => ['attributes' => ['read_at' => null]]]
        )
            // should not return the content.
            ->assertStatus(Response::HTTP_UNAUTHORIZED)
            ->assertJsonFragment(["message" => "Unauthorized"])
            ->assertJsonFragment(["title" => "Your request is unauthorized"]);
    }

    /**
     * Test the user can delete the notification.
     */
    public function testDeleteNotification()
    {
        $this->expectException(ModelNotFoundException::class);
        /** @var DatabaseNotification $notification */
        $notification = $this->teacherUser2->notifications->first();
        $this->assertFalse($notification->read());

        // Mark notification as read.
        $this->actingAs($this->teacherUser2)->delete('/api/notifications/' . $notification->getKey())
            ->assertSuccessful();
        // Exception here
        $notification->refresh();

        // Try to delete someone else notification
        $notification = $this->teacherUser1->notifications->first();
        $this->actingAs($this->teacherUser2)->delete('/api/notifications/' . $notification->getKey())
            // should not return the content.
            ->assertStatus(Response::HTTP_UNAUTHORIZED)
            ->assertJsonFragment(["message" => "Unauthorized"])
            ->assertJsonFragment(["title" => "Your request is unauthorized"]);
    }
}
