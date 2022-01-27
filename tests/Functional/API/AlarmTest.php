<?php

namespace Tests\Functional\API;

use App\Http\ApiCodes;
use App\Http\Controllers\API\Transformers\AlarmTransformer;
use App\Models\Alarm;
use App\Models\School;
use App\Models\User;
use Illuminate\Http\Response;
use OwenIt\Auditing\Models\Audit;
use Spatie\Permission\Models\Permission;
use Tests\ApiTestCase;

class AlarmTest extends ApiTestCase
{
    public function setUp(): void
    {
        parent::setUp();
        // Disable auditing from this point on
//        Alarm::disableAuditing();
        Alarm::truncate();
    }

    /**
     * Test alarm wouldn't created without a reason.
     */
    public function testCreateAlarmNoReason()
    {
        $this->assertTrue($this->user->can('alarm.create'));

        $this->post('/api/alarms')
            ->assertJsonFragment(['title' => 'Failed model validation'])
            ->assertJsonFragment(['message' => 'data.attributes.reason: The data.attributes.reason field is required.'])
            ->assertJsonFragment(['code' => ApiCodes::MODEL_VALIDATION_ERROR]);
    }

    public function testCreateAlarmNoJson()
    {
        $this->assertTrue($this->user->can('alarm.create'));

        // This would POST NOT json format!
        $this->post('/api/alarms', ['data' => ['attributes' => ['reason' => 'something']]])
            ->assertJsonFragment(['title' => 'Invalid JSON API request object'])
            ->assertJsonFragment(['message' => 'Malformed Json API Request Exception'])
            ->assertJsonFragment(['code' => ApiCodes::MALFORMED_REQUEST]);
    }

    /**
     * Creates a new Alarm
     * @throws \Throwable
     */
    public function testCreateAlarm()
    {
        $this->assertTrue($this->user->can('alarm.create'));

        $response = $this->postJson('/api/alarms', ['data' => ['attributes' => ['reason' => 'something']]])
            ->assertJsonFragment(['type' => AlarmTransformer::JSON_OBJ_TYPE]);

        $result = $response->decodeResponseJson()['data']['attributes'];
        $this->assertNotNull('id');
        $this->assertNotNull($result['created_at']);
        $this->assertNotNull($result['updated_at']);
        $this->assertNull($result['disarmed_at']);
    }

    /**
     * Retrieve Alarm list via API call.
     *
     * @return void
     * @throws \Throwable
     */
    public function testGetAlarms()
    {
        Alarm::initiate($this->user, 'reason1');
        Alarm::initiate($this->user, 'reason2');
        Alarm::initiate(User::factory()->create(['school_id' => School::factory()->create()->id]), 'reason3');
        $this->assertEquals(3, Alarm::count());

        // Validate includes as well
        $response = $this->get('/api/alarms?include=users');
        $response->assertJsonFragment(['initiated_by_user_id' => $this->user->id]);
        $response->assertJsonFragment(['name' => $this->user->name]);

        $response->assertJsonFragment(['reason' => 'reason1']);
        $response->assertJsonFragment(['reason' => 'reason2']);
        $response->assertDontSee('reason3');
    }

    /*
     * Test user w/o alarm.view permission can't the the alarm info.
     */
    public function testGetAlarmNoPermission()
    {
        $alarm = Alarm::initiate($this->user, 'reason1');
        $this->actingAs(User::factory(['school_id' => $this->user->school_id])->create());
        $response = $this->get("/api/alarms/{$alarm->id}");

        $response->assertJsonFragment(['message' => 'Unauthorized']);
        $response->assertJsonFragment(['code' => ApiCodes::UNAUTHORIZED]);
    }

    /**
     * Validate we can get the Alarm with `alarm.view` permission
     */
    public function testGetAlarm()
    {
        $alarm = Alarm::initiate($this->user, 'reason1');

        $this->user->givePermissionTo('alarm.view');

        $this->get("/api/alarms/{$alarm->id}")
            ->assertJsonFragment(['reason' => 'reason1']);
    }

    public function testDisarmAlarm()
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
            $result = $response->decodeResponseJson()['data']['attributes'];
            // Each time there should be disarmed_at data added to disarm_data meta information.
            $this->assertCount($id + 1, $result['disarm_data']['disarmed_at']);

            if ($id < 2) {
                $this->assertNull($result['disarmed_at']);
            } else {
                $this->assertNotNull($result['disarmed_at']);
            }
        }

        $this->actingAs($this->user);
        $this->user->givePermissionTo(['alarm.delete', 'alarm.view']);
        $response = $this->delete('/api/alarms/' . $alarm->id);

        $response->assertJsonFragment(['message' => 'This user set the alarm'])
            ->assertJsonFragment(['code' => (string)Response::HTTP_BAD_REQUEST]);

        $response = $this->get("/api/alarms/{$alarm->id}?include=users");
        $response->assertJsonFragment(['is_armed' => false]);
        $response->assertJsonFragment(['disarm_countdown' => 0]);

        foreach ($users as $user) {
            $response->assertJsonFragment(['id' => $user->getKey()]);
        }
    }
}
