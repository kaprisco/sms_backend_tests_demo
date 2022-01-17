<?php

namespace Tests\Functional\API;

use App\Http\ApiCodes;
use App\Models\Course;
use App\Models\Group;
use App\Models\Room;
use App\Models\School;
use App\Models\Term;
use App\Models\User;
use Tests\ApiTestCase;

class RoomTest extends ApiTestCase
{
    private Room $roomA;
    private Room $roomB;

    public function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->adminUser);
        $this->roomA = Room::factory(['name' => 'Room A'])->create();
        $this->assertEquals($this->roomA->school_id, $this->adminUser->school_id);

        $this->roomB = Room::factory(['name' => 'Room B'])->create();
        $this->assertEquals($this->roomB->school_id, $this->adminUser->school_id);
    }

    public function testCreateRoom()
    {
        $this->actingAs($this->adminUser)->postJson(
            '/api/rooms',
            [
                'data' => [
                    'attributes' => [
                        'name' => "Room Z",
                        'info' => [
                            'floor' => 'Z',
                            'room_number' => '#303',
                        ]
                    ]
                ]
            ]
        )->assertJsonFragment(['name' => 'Room Z'])
            ->assertJsonFragment(['floor' => 'Z'])
            ->assertJsonFragment(["room_number" => "#303"]);

        $this->assertEquals($this->adminUser->school_id, Room::whereName("Room Z")->first()->school_id);

        // Try to rename the group with other User.
        $this->actingAs($this->teacherUser1)->postJson(
            '/api/rooms',
            ['data' => ['attributes' => ['name' => "Room A",]]]
        )->assertJsonFragment(['message' => 'Unauthorized']);
    }

    /**
     * Retrieve Room information via API call.
     *
     * @return void
     * @throws \Throwable
     */
    public function testGetRooms()
    {
        $response = $this->get('/api/rooms')
            ->assertJsonFragment(['name' => 'Room A'])
            ->assertJsonFragment(['name' => 'Room B']);

        /** @var string $id Get first Group ID */
        $id = $response->decodeResponseJson()['data'][0]['id'];
        $this->assertIsString($id, 'Should be string UUID');

        // Just a user cannot retrieve the Room
        $this->actingAs($this->parentUser)->get('/api/rooms/' . $id)
            ->assertJsonFragment(['message' => 'Unauthorized']);

        // Admin can retrieve it.
        $this->actingAs($this->adminUser)->get('/api/rooms/' . $id)
            ->assertSuccessful()
            ->assertJsonFragment(['name' => Room::find($id)->name]);
    }

    public function testRoomSearch()
    {
        // Name search
        $this->get('/api/rooms/?q=A')
            ->assertJsonFragment(['total' => 1])
            ->assertJsonFragment(['name' => 'Room A']);
    }

    public function testDeleteRoom()
    {
        $this->actingAs($this->adminUser)
            ->deleteJson('/api/rooms/' . $this->roomA->getKey())->assertSuccessful();
    }

    public function testUpdateRoom()
    {
        $response = $this->actingAs($this->adminUser)->patchJson(
            '/api/rooms/' . $this->roomA->getKey(),
            ['data' => ['attributes' => ['name' => 'Room Z']]]
        );

        $response->assertJsonFragment(['name' => 'Room Z']);

        // Try to update other Room.
        $this->actingAs($this->teacherUser1)->patchJson(
            '/api/rooms/' . $this->roomB->getKey(),
            ['data' => ['attributes' => ['name' => 'Room Z']]]
        )->assertJsonFragment(['message' => 'Unauthorized'])
            ->assertJsonFragment(['title' => 'Your request is unauthorized'])
            ->assertJsonFragment(['code' => ApiCodes::UNAUTHORIZED]);
    }
}
