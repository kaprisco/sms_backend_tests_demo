<?php

namespace Tests\Functional\API;

use App\Http\Controllers\API\Transformers\CourseTransformer;
use App\Models\Course;
use App\Models\Term;
use App\Models\User;
use Carbon\Carbon;
use Tests\ApiTestCase;

class CourseEventsTest extends ApiTestCase
{
    private Term $term;

    public function setUp(): void
    {
        parent::setUp();
        // Disable auditing from this point on
        Course::disableAuditing();
        Course::truncate();

        $this->assertTrue($this->teacherUser1->can('course.create'));
        $this->actingAs($this->teacherUser1);

        $this->term = Term::factory([
            'start_at' => Carbon::create(2030)->startOfYear(),
            'end_at' => Carbon::create(2030)->startOfYear()->endOfMonth(),
        ])->create();
    }

    /**
     * Creates a new Course, attach a few Students for the Course and check Calendar Events have these
     * Students as attendees.
     * @throws \Throwable
     */
    public function testCreateCourse()
    {
        $response = $this->postJson('/api/courses', ['data' => ['attributes' => [
            'term_id' => $this->term->getKey(),
            'rrule' => "DTSTART=" . now()->toDateString() . ";FREQ=DAILY;BYDAY=MO,WE",
            'name' => 'Chemistry',
        ]]])
            ->assertJsonFragment(['type' => CourseTransformer::JSON_OBJ_TYPE]);

        $result = $response->decodeResponseJson()['data']['attributes'];
        $this->assertNotNull('id');
        $this->assertNotNull($result['created_at']);
        $this->assertNotNull($result['updated_at']);
        $this->assertNull($result['start_at']);
        $courseId = $response->decodeResponseJson()['data']['id'];

        $this->teacherUser1->givePermissionTo(['calendar.index', 'calendar.view']);

        // There should be 9 events of Chemistry generated in the given Term
        $this->get('/api/calendars')
            ->assertJsonFragment(['total' => 9])
            ->assertJsonFragment(['summary' => 'Chemistry']);

        /** @var User $student */
        $student = User::factory(['name' => 'Student B'])->create()->assignRole(Course::ROLE_STUDENT);
        $student2 = User::factory(['name' => 'Student C'])->create()->assignRole(Course::ROLE_STUDENT);

        // Add these Students to the Course.
        $this->postJson(
            "/api/courses/$courseId/add_students?fields[course]=student_count",
            ['users' => [
                ['user_id' => $student->getKey(), 'course_role' => Course::ROLE_STUDENT],
                ['user_id' => $student2->getKey(), 'course_role' => Course::ROLE_STUDENT],
            ]]
        )->assertJsonFragment(['student_count' => 2]);

        // Calendar Events now should have these Students as attendees.
        $this->get('/api/calendars?per_page=1&include=users')
            ->assertJsonFragment(['name' => 'Teacher A'])
            ->assertJsonFragment(['name' => 'Student B'])
            ->assertJsonFragment(['name' => 'Student C'])
            ->assertJsonFragment(['total' => 9])
            ->assertJsonFragment(['summary' => 'Chemistry']);

        // Delete one of Students from the Course.
        $this->postJson(
            "/api/courses/$courseId/delete_students?fields[course]=student_count",
            ['users' => [
                // Delete Student B
                ['user_id' => $student->getKey()],
            ]]
        )->assertJsonFragment(['student_count' => 1]);

//        $this->get("/api/courses/$courseId?include=users,term");

        // Calendar Events now should have these Students as attendees.
        $this->get('/api/calendars?per_page=1&include=users')
            ->assertJsonFragment(['name' => 'Teacher A'])
            // Student C should be still attached
            ->assertJsonFragment(['name' => 'Student C'])
            // but Student B should be gone.
            ->assertDontSeeText('Student B')
            ->assertJsonFragment(['total' => 9])
            ->assertJsonFragment(['summary' => 'Chemistry']);
    }

    /**
     * Test the Teacher can update the Course name and all further Events would reflect the change.
     */
    public function testCourseUpdate()
    {
        $response = $this->postJson('/api/courses', ['data' => ['attributes' => [
            'term_id' => $this->term->getKey(),
            'rrule' => "DTSTART=" . now()->toDateString() . ";FREQ=DAILY;BYDAY=MO,WE",
            'name' => 'Chemistry',
        ]]])
            ->assertJsonFragment(['type' => CourseTransformer::JSON_OBJ_TYPE]);
        $courseId = $response->decodeResponseJson()['data']['id'];

        // Primary teacher can change the name.
        $response = $this->patchJson(
            '/api/courses/' . $courseId,
            ['data' => ['attributes' => [
                'name' => 'Chem2',
            ]]]
        );
        $response->assertJsonFragment(['name' => 'Chem2']);

        $this->teacherUser1->givePermissionTo(['calendar.index', 'calendar.view']);

        // Calendar Events now should have the new name.
        $this->get('/api/calendars?per_page=1')
            ->assertDontSeeText('Chemistry')
            ->assertJsonFragment(['total' => 9])
            ->assertJsonFragment(['summary' => 'Chem2']);
    }
}
