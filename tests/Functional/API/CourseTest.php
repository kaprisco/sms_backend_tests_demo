<?php

namespace Tests\Functional\API;

use App\Http\ApiCodes;
use App\Http\Controllers\API\Transformers\CourseTransformer;
use App\Models\Course;
use App\Models\Term;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Response;
use RRule\RRule;
use Tests\ApiTestCase;

class CourseTest extends ApiTestCase
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
     * Test Course wouldn't be created without a name.
     */
    public function testCreateCourseNoName()
    {
        $this->post('/api/courses')
            ->assertJsonFragment(['title' => 'Failed model validation'])
            ->assertJsonFragment(['message' => 'data.attributes.name: The data.attributes.name field is required.'])
            ->assertJsonFragment(['code' => ApiCodes::MODEL_VALIDATION_ERROR]);
    }

    public function testCreateCourseNoJson()
    {
        // This would POST NOT json format!
        $this->post('/api/courses', ['data' => ['attributes' => ['name' => 'something']]])
            ->assertJsonFragment(['title' => 'Invalid JSON API request object'])
            ->assertJsonFragment(['message' => 'Malformed Json API Request Exception'])
            ->assertJsonFragment(['code' => ApiCodes::MALFORMED_REQUEST]);
    }

    /**
     * This negative test will validate foreign key for term_id.
     */
    public function testCreateWrongTerm()
    {
        $this->postJson('/api/courses', ['data' => ['attributes' => [
            'term_id' => 666,
            'name' => 'Chemistry',
        ]]])
            ->assertJsonFragment(['code' => ApiCodes::DB_ERROR]);
    }

    /**
     * Creates a new Course
     * @throws \Throwable
     */
    public function testCreateCourse()
    {
        $response = $this->postJson('/api/courses', ['data' => ['attributes' => [
            'term_id' => $this->term->getKey(),
            'name' => 'Chemistry',
        ]]])
            ->assertJsonFragment(['type' => CourseTransformer::JSON_OBJ_TYPE]);

        $result = $response->decodeResponseJson()['data']['attributes'];
        $this->assertNotNull('id');
        $this->assertNotNull($result['created_at']);
        $this->assertNotNull($result['updated_at']);
        $this->assertNull($result['start_at']);
    }

    public function testAddCourseWrongUser()
    {
        $course = Course::factory(['name' => 'Chemistry'])->create();

        /** @var User $student */
        $student3 = User::factory(['name' => 'Student C'])->create();//->assignRole('Student');

        // Add this Student to the Course.
        $this->postJson(
            "/api/courses/{$course->id}/add_students?include=users",
            ['users' => [
                ['user_id' => $student3->getKey(), 'course_role' => Course::ROLE_STUDENT],
            ]]
        )->assertJsonFragment(['message' => 'User is not a Student or Teacher']);
    }

    public function testAddCourseStudents()
    {
        $course = Course::factory(['name' => 'Chemistry'])
            ->hasAttached(User::factory(['name' => 'Student']), ['role' => Course::ROLE_STUDENT], 'students')
            ->create();

        /** @var User $student */
        $student = User::factory(['name' => 'Student B'])->create()->assignRole('Student');
        $student2 = User::factory(['name' => 'Student C'])->create()->assignRole('Student');

        // Add this Student to the Course.
        $this->postJson(
            "/api/courses/{$course->id}/add_students?include=users",
            ['users' => [
                ['user_id' => $student->getKey(), 'course_role' => Course::ROLE_STUDENT],
                ['user_id' => $student2->getKey(), 'course_role' => Course::ROLE_STUDENT],
            ]]
        )->assertJsonFragment(['name' => 'Student'])
            ->assertJsonFragment(['name' => 'Student B'])
            ->assertJsonFragment(['course_role' => Course::ROLE_STUDENT]);

        // Delete Student to the Course.
        $this->postJson(
            "/api/courses/{$course->id}/delete_students?include=users",
            ['users' => [
                // Delete Student B.
                ['user_id' => $student->getKey()],
            ]]
        )->assertJsonFragment(['name' => 'Student'])
            ->assertDontSeeText('Student B')
            ->assertJsonFragment(['course_role' => Course::ROLE_STUDENT]);
    }

    /**
     * Validate we can get the Course with `course.view` permission
     */
    public function testGetCourse()
    {
        $course = Course::factory(['name' => 'Chem'])->create();

        $this->get("/api/courses/{$course->id}?include=users,term")
            ->assertJsonFragment(['name' => 'Chem'])
            // teacher role should be included in User relationship.
            ->assertJsonFragment(['course_role' => 'primary_teacher'])
            ->assertJsonFragment(['id' => $this->teacherUser1->getKey()])
            // Here we check if Term is included
            ->assertJsonFragment(['type' => 'term'])
            ->assertJsonFragment(['name' => $course->term->name]);

        // Remove permission and the course should be no more accessible
        $this->teacherUser1->removeRole('Teacher');

        $this->get("/api/courses/{$course->id}")
            ->assertJsonFragment(['code' => (string) Response::HTTP_UNAUTHORIZED]);

        // Teacher 2 cannot access that Course, since he is not associated with the Course.
        $this->actingAs($this->teacherUser2)->get("/api/courses/{$course->id}")
            ->assertJsonFragment(['code' => (string) Response::HTTP_UNAUTHORIZED]);
    }

    /**
     * Retrieve Courses list via API call.
     *
     * @return void
     * @throws \Throwable
     */
    public function testGetCourses()
    {
        $this->actingAs($this->teacherUser2);
        Course::factory(['name' => 'Other'])->create();

        $this->actingAs($this->teacherUser1);
        Course::factory(['name' => 'Chem'])->forTerm(['name' => 'Term 2020'])->create();
        Course::factory(['name' => 'Math'])->forTerm(['name' => 'Term 2021'])->create();

        // Validate User includes as well.
        $this->get('/api/courses?include=users')
            ->assertJsonFragment(['name' => $this->teacherUser1->name])
            ->assertJsonFragment(['name' => 'Math'])
            ->assertJsonFragment(['name' => 'Chem'])
            ->assertJsonFragment(['name' => $this->teacherUser1->name])
            // Second teacher name should not be seen.
            ->assertDontSeeText($this->teacherUser2->name)
            ->assertJsonFragment(['course_role' => 'primary_teacher']);

        // Validate Term includes, both Courses should be displayed.
        $this->get('/api/courses?include=term')
        ->assertJsonFragment(['name' => 'Term 2020'])
        ->assertJsonFragment(['name' => 'Term 2021']);
    }

    /**
     * Test Course filter for the given Term.
     */
    public function testFilterTermCourses()
    {
        $this->actingAs($this->teacherUser1);
        $course = Course::factory(['name' => 'Chem'])->forTerm(['name' => 'Term 2020'])->create();
        $this->actingAs($this->teacherUser2);
        Course::factory(['name' => 'Other'])->for($course->term)->create();
        // Here we'll have two Courses for the same Term, but for other teachers.
        $this->actingAs($this->teacherUser1);

        // Here is another Course for totally other Term. Should not be seen.
        Course::factory(['name' => 'Math'])->forTerm(['name' => 'Term 2021'])->create();

        $this->get("/api/terms/{$course->term->id}/courses?include=term")
            ->assertJsonFragment(['name' => 'Chem'])
            ->assertJsonFragment(['name' => 'Term 2020'])
            // Only one course should be seed for teacherUser1
            ->assertJsonFragment(['count' => 1]);
    }

    /**
     * Test the Teacher can update the Course name.
     */
    public function testCourseUpdate()
    {
        $course1 = Course::factory(['name' => 'Chem1'])
            ->hasAttached($this->teacherUser2, ['role' => Course::ROLE_TEACHER], 'teachers')
            ->create();

        // Primary teacher can change the name.
        $response = $this->patchJson(
            '/api/courses/' . $course1->getKey(),
            ['data' => ['attributes' => [
                'name' => 'Chem2',
            ]]]
        );
        $response->assertJsonFragment(['name' => 'Chem2']);

        // Secondary teacher can change the name.
        $response = $this->actingAs($this->teacherUser2)->patchJson(
            '/api/courses/' . $course1->getKey(),
            ['data' => ['attributes' => [
                'name' => 'Chem1',
            ]]]
        );
        $response->assertJsonFragment(['name' => 'Chem1']);
    }
}
