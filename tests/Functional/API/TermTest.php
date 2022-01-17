<?php

namespace Tests\Functional\API;

use App\Http\ApiCodes;
use App\Http\Controllers\API\Transformers\TermTransformer;
use App\Models\Course;
use App\Models\Term;
use Illuminate\Database\Eloquent\Factories\Sequence;
use Illuminate\Http\Response;
use Tests\ApiTestCase;

class TermTest extends ApiTestCase
{
    private Term $term;

    public function setUp(): void
    {
        parent::setUp();
        // Disable auditing from this point on
//        Term::disableAuditing();

        $this->assertTrue($this->teacherUser1->can('term.create'));
        $this->actingAs($this->teacherUser1);
    }

    /**
     * Test Term wouldn't be created without a name.
     */
    public function testCreateTermNoName()
    {
        $this->post('/api/terms')
            ->assertJsonFragment(['title' => 'Failed model validation'])
            ->assertJsonFragment(['message' => 'data.attributes.name: The data.attributes.name field is required.'])
            ->assertJsonFragment(['message' =>
                'data.attributes.start_at: The data.attributes.start at field is required.'])
            ->assertJsonFragment(['message' =>
                'data.attributes.end_at: The data.attributes.end at field is required.'])
            ->assertJsonFragment(['code' => ApiCodes::MODEL_VALIDATION_ERROR]);
    }

    /**
     * Creates a new Term
     * @throws \Throwable
     */
    public function testCreateTerm()
    {
        $response = $this->postJson('/api/terms', ['data' => ['attributes' => [
            'name' => 'Term A',
            'start_at' => now()->startOfMonth(),
            'end_at' => now()->endOfMonth(),
        ]]])
            ->assertJsonFragment(['type' => TermTransformer::JSON_OBJ_TYPE])
            ->assertJsonFragment(['name' => 'Term A']);

        $result = $response->decodeResponseJson()['data']['attributes'];
        $this->assertNotNull('id');
        $this->assertNotNull($result['created_at']);
        $this->assertNotNull($result['updated_at']);
        $this->assertNotNull($result['start_at']);
        $this->assertNotNull($result['end_at']);
    }

    /**
     * Validate we can get the Term with `term.view` permission
     */
    public function testGetTerm()
    {
        $term = Term::factory(['name' => 'Term A'])->create();

        $this->get("/api/terms/{$term->id}?include=audit")
            ->assertJsonFragment(['name' => 'Term A'])
            // teacher role should be included in User relationship.
            ->assertJsonFragment(['id' => $term->getKey()]);

        // Remove permission and the course should be no more accessible
        $this->teacherUser1->removeRole(Course::ROLE_TEACHER);

        $this->get("/api/terms/{$term->id}")
            ->assertJsonFragment(['code' => (string) Response::HTTP_UNAUTHORIZED]);
    }

    /**
     * Retrieve Terms list via API call.
     *
     * @return void
     * @throws \Throwable
     */
    public function testGetTerms()
    {
        $this->actingAs($this->teacherUser1);
        Term::factory()->state(new Sequence(
            ['name' => 'Term A'],
            ['name' => 'Term B'],
        ))->count(2)->create();

        $this->get('/api/terms')
            ->assertJsonFragment(['name' => 'Term A'])
            ->assertJsonFragment(['name' => 'Term B']);
    }

    /**
     * Test the Teacher can update the Term name.
     */
    public function testTermUpdate()
    {
        Term::enableAuditing();

        $term = Term::factory(['name' => 'Term A'])->create();

        // Primary teacher can change the name.
        $response = $this->patchJson(
            '/api/terms/' . $term->getKey() . '?include=audit',
            ['data' => ['attributes' => [
                'name' => 'Term B',
            ]]]
        );
        $response->assertJsonFragment(['name' => 'Term B']);

        // Test audit.
        // Add audit permission.
        $this->teacherUser1->givePermissionTo('audit.view');

        $this->get("/api/terms/{$term->id}?include=audit")
            ->assertJsonFragment(["old" => "Term A"])
            ->assertJsonFragment(["new" => "Term B"]);

        // Other User can not change the name.
        $this->actingAs($this->parentUser)->patchJson(
            '/api/terms/' . $term->getKey(),
            ['data' => ['attributes' => [
                'name' => 'Term Z',
            ]]]
        )->assertJsonFragment(['code' => (string)Response::HTTP_UNAUTHORIZED]);
    }

    public function testTermDelete()
    {
        $term = Term::factory(['name' => 'Term A'])->create();

        // By default teacher can not delete Term.
        $this->delete('/api/terms/' . $term->getKey())
            ->assertJsonFragment(['code' => (string)Response::HTTP_UNAUTHORIZED]);

        $this->actingAs($this->supervisorUser)
            ->delete('/api/terms/' . $term->getKey())
            ->assertSuccessful();

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        $term->refresh();
    }
}
