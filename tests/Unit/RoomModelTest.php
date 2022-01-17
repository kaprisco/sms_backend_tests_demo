<?php

namespace Tests\Unit;

use App\Models\Room;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\ApiTestCase;

class RoomModelTest extends ApiTestCase
{
    use RefreshDatabase;

    /**
     * @return void
     */
    public function testRoomModel()
    {
        /** @var Room $room */
        $room = Room::factory()->create();
        $this->assertNotNull($room);
        $this->assertNotNull($room->school_id);
        $this->assertEquals([], $room->info);
        $room->info = ['floor' => 3, 'room_number' => 303];
        $room->save();
        $this->assertEquals(['floor' => 3, 'room_number' => 303], Room::all()->first()->info);
    }
}
