<?php

namespace Tests\Functional\API;

use App\Models\Alarm;
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
        $this->actingAs($this->teacherUser2)->get('/api/notifications')
            ->assertJsonFragment(['total' => 2])
            ->assertJsonFragment(['unread' => 2])
            ->assertJsonFragment(["title" => "Alarm triggered by Admin <admin@demo.com>"])
            ->assertJsonFragment(["description" => "Reason is: reason2"])
            ->assertJsonFragment(["description" => "Reason is: reason1"]);
    }

    /**
     * Get the specific notification and test we can't retrieve someone else notification.
     */
    public function testGetNotification()
    {
        $notification = $this->teacherUser2->notifications->first();
        $this->actingAs($this->teacherUser2)->get('/api/notifications/' . $notification->getKey())
            ->assertJsonFragment(["title" => "Alarm triggered by Admin <admin@demo.com>"])
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
