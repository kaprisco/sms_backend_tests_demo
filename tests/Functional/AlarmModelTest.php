<?php

namespace Tests\Functional;

use App\Models\Alarm;
use App\Models\School;
use App\Models\User;
use Tests\FunctionalTestCase;

class AlarmModelTest extends FunctionalTestCase
{
    /**
     * Validate School relationship for Alarm
     * Validate User relationship for Alarm
     */
    public function testAlarmRelations()
    {
        $alarm = Alarm::initiate($this->user, 'something');

        $this->assertNotEmpty($alarm->id);
        // validate relationships.
        $this->assertEquals($this->user->school_id, $alarm->school->id);
        $this->assertEquals($this->user->id, $alarm->user->id);
    }

    /**
     * Test Alarm model business logic.
     *
     * @return void
     */
    public function testAlarmModel()
    {
//        $school = School::factory()->create();
//        $this->user->school()->associate($school)->save();

        $alarm = Alarm::arm($this->user, 'something');
        $alarm->save();

        $this->assertNotEmpty($alarm->id);

        $this->assertEquals(Alarm::STATUS_ACTIVE, $alarm->status);
        $this->assertEquals($this->user->school->id, $alarm->school_id);
        $this->assertNull($alarm->disarmed_at);

        $this->assertTrue($alarm->isArmed());

        $this->assertEquals(
            Alarm::DISARM_USER_COUNT,
            $alarm->disarm_data['disarm_countdown'],
            'should be initial number of users required to disarm the alarm'
        );

        $this->assertEquals('This user set the alarm', $alarm->disarm($this->user));

        // Create 3 users to disarm the Alarm.
        $users = User::factory()->count(3)->create();
        foreach ($users as $user) {
            $this->assertTrue($alarm->disarm($user));
        }
        $this->assertFalse($alarm->isArmed());
        $this->assertEquals(Alarm::STATUS_DISARMED, $alarm->status);
        $this->assertNotNull($alarm->disarmed_at);

        // 4 users involved, 1 initiator and 3 disarmers.
        $this->assertCount(4, $alarm->getAllUsers());
    }
}
