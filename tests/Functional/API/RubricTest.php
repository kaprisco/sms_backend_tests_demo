<?php

namespace Tests\Functional\API;

use App\Http\ApiCodes;
use App\Models\Course;
use App\Models\Group;
use App\Models\Room;
use App\Models\Rubric;
use App\Models\School;
use App\Models\Term;
use App\Models\User;
use Tests\ApiTestCase;

class RubricTest extends ApiTestCase
{
    private Rubric $rubricA;
    private Rubric $rubricB;

    public function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->adminUser);
        $this->rubricA = Rubric::factory(['name' => 'Rubric A'])->create();
        $this->assertEquals($this->rubricA->school_id, $this->adminUser->school_id);

        $this->rubricB = Rubric::factory(['name' => 'Rubric B'])->create();
        $this->assertEquals($this->rubricB->school_id, $this->adminUser->school_id);
    }

    public function testCreateRubric()
    {
        $this->actingAs($this->adminUser)->postJson(
            '/api/rubrics',
            [
                'data' => [
                    'attributes' => [
                        'name' => "Rubric Z",
                        'data' => [
                            'att1' => '1',
                            'att2' => '2',
                        ]
                    ]
                ]
            ]
        )->assertJsonFragment(['name' => 'Rubric Z'])
            ->assertJsonFragment(['att1' => '1'])
            ->assertJsonFragment(["att2" => "2"]);

        $this->assertEquals($this->adminUser->school_id, Rubric::whereName("Rubric Z")->first()->school_id);

        // Try to rename rubric with User without permission.
        $this->actingAs($this->parentUser)->postJson(
            '/api/rubrics',
            ['data' => ['attributes' => ['name' => "Rubric A",]]]
        )->assertJsonFragment(['message' => 'Unauthorized']);
    }

    /**
     * Retrieve Rubric information via API call.
     *
     * @return void
     * @throws \Throwable
     */
    public function testGetRubrics()
    {
        $response = $this->get('/api/rubrics')
            ->assertJsonFragment(['name' => 'Rubric A'])
            ->assertJsonFragment(['name' => 'Rubric B']);

        /** @var string $id Get first Rubric ID */
        $id = $response->decodeResponseJson()['data'][0]['id'];
        $this->assertIsString($id, 'Should be string UUID');

        // Just a user cannot retrieve the Rubric
        $this->actingAs($this->supervisorUser)->get('/api/rubrics/' . $id)
            ->assertJsonFragment(['message' => 'Unauthorized']);

        // Admin can retrieve it.
        $this->actingAs($this->adminUser)->get('/api/rubrics/' . $id)
            ->assertSuccessful()
            ->assertJsonFragment(['name' => Rubric::find($id)->name]);
    }

    public function testRubricSearch()
    {
        // Search for the name.
        $this->get('/api/rubrics/?q=Rubric A')
            ->assertSuccessful()
            ->assertJsonFragment(['name' => 'Rubric A']);
    }

    public function testDeleteRubric()
    {
        $this->actingAs($this->adminUser)
            ->deleteJson('/api/rubrics/' . $this->rubricA->getKey())->assertSuccessful();
    }

    public function testUpdateRubric()
    {
        $response = $this->actingAs($this->adminUser)->patchJson(
            '/api/rubrics/' . $this->rubricA->getKey(),
            ['data' => ['attributes' => ['name' => 'Rubric Z']]]
        );

        $response->assertJsonFragment(['name' => 'Rubric Z']);

        // Try to update other Room.
        $this->actingAs($this->supervisorUser)->patchJson(
            '/api/rubrics/' . $this->rubricB->getKey(),
            ['data' => ['attributes' => ['name' => 'Rubric Z']]]
        )->assertJsonFragment(['message' => 'Unauthorized'])
            ->assertJsonFragment(['title' => 'Your request is unauthorized'])
            ->assertJsonFragment(['code' => ApiCodes::UNAUTHORIZED]);
    }
}
