<?php

namespace Tests\Functional\API;

use App\Models\Alarm;
use App\Models\User;
use Tests\ApiTestCase;

class AuditTest extends ApiTestCase
{
    public function setUp(): void
    {
        parent::setUp();
        Alarm::enableAuditing();
        Alarm::truncate();
    }

    public function testAlarmAudit()
    {
        $this->user->givePermissionTo('alarm.view');

        $alarm = Alarm::initiate($this->user, 'something');

        /** @var User[] $users */
        $users = User::factory()->count(3)->create(['school_id' => $this->user->school_id]);

        foreach ($users as $id => $user) {
            $user->givePermissionTo(['alarm.delete', 'alarm.view']);
            $this->actingAs($user);
            $this->assertTrue($this->user->can('alarm.delete'));
            $response = $this->delete('/api/alarms/' . $alarm->id);
        }

        $this->actingAs($this->user);
        $this->user->givePermissionTo(['alarm.delete', 'alarm.view']);
        $response = $this->delete('/api/alarms/' . $alarm->id);

        // Here we shouldn't see any audit log, since there are no such ability enabled for the user.
        $this->get('/api/alarms/' . $alarm->id . '?include=audit')
            ->assertJsonMissing(["new" => "disarmed"]);

        // Add audit permission.
        $this->user->givePermissionTo('audit.view');

        // Now we should see audit log for the alarm.
        $response = $this->get('/api/alarms/' . $alarm->id . '?include=audit');
        // Check for a part of audit log
        $response->assertJsonFragment(["status" => [
            "new" => "disarmed",
            "old" => "active"
        ]]);
    }
}
