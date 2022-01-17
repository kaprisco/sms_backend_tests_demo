<?php

namespace Tests\Functional\API;

use App\Http\Controllers\API\Transformers\CourseTransformer;
use App\Models\Course;
use App\Models\Term;
use App\Models\User;
use Carbon\Carbon;
use Tests\ApiTestCase;

class CourseEventsParentsViewTest extends ApiTestCase
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
     * @throws \Throwable
     */
    public function testViewStudentsCalendarEvents()
    {
        /** @var User $studentA */
        $studentA = User::factory(['name' => 'Student A'])->create()->assignRole(Course::ROLE_STUDENT);
        /** @var User $studentA1 */
        $studentA1 = User::factory(['name' => 'Student A1'])->create()->assignRole(Course::ROLE_STUDENT);
        /** @var User $studentB */
        $studentB = User::factory(['name' => 'Student B'])->create()->assignRole(Course::ROLE_STUDENT);
        // Attach Parents to Students.
        //$studentA->parents()->sync([$this->parentUser->getKey()]);
        $studentB->parents()->sync([$this->parentUser2->getKey()]);

        // First parent have two children.
        // Parent A -> Student A, Student A1
        // Parent B -> Student B
        $this->parentUser->students()->attach([$studentA->getKey(), $studentA1->getKey()]);

        $response = $this->postJson('/api/courses', ['data' => ['attributes' => [
            'term_id' => $this->term->getKey(),
            'rrule' => "DTSTART=2030-01-01 10:00;FREQ=DAILY;COUNT=1",
            'name' => 'Chemistry, StudentAB',
            'users' => [
                ['user_id' => $studentA->getKey(), 'course_role' => Course::ROLE_STUDENT],
                ['user_id' => $studentB->getKey(), 'course_role' => Course::ROLE_STUDENT],
            ]
        ]]])
            // Here we aren't validating all the details, that's done in other tests.
            ->assertSuccessful();

        $response = $this->postJson('/api/courses', ['data' => ['attributes' => [
            'term_id' => $this->term->getKey(),
            'rrule' => "DTSTART=2030-01-01 11:00;FREQ=DAILY;COUNT=1",
            'name' => 'Chemistry, StudentB, A1',
            'users' => [
                ['user_id' => $studentA1->getKey(), 'course_role' => Course::ROLE_STUDENT],
                ['user_id' => $studentB->getKey(), 'course_role' => Course::ROLE_STUDENT],
            ]
        ]]])
            // Here we aren't validating all the details, that's done in other tests.
            ->assertSuccessful();


        $response = $this->postJson('/api/courses', ['data' => ['attributes' => [
            'term_id' => $this->term->getKey(),
            'rrule' => "DTSTART=2030-01-01 12:00;FREQ=DAILY;COUNT=1",
            'name' => 'Chemistry, StudentA',
            'users' => [
                ['user_id' => $studentA->getKey(), 'course_role' => Course::ROLE_STUDENT],
            ]
        ]]])
            // Here we aren't validating all the details, that's done in other tests.
            ->assertSuccessful();

        // Calendar Events should be seen by Parents whose Students are attendees.
        $this->actingAs($this->parentUser)->get('/api/calendars')
            // Roles should be seen in attendees.
            ->assertJsonFragment(['role' => Course::ROLE_PRIMARY_TEACHER])
            ->assertJsonFragment(['role' => Course::ROLE_STUDENT])
            // These events due to StudentA present in both Courses.
            ->assertJsonFragment(['summary' => 'Chemistry, StudentA'])
            ->assertJsonFragment(['summary' => 'Chemistry, StudentAB'])
            // These events seen from StudentA1 courses.
            ->assertJsonFragment(['summary' => 'Chemistry, StudentB, A1'])
            ->assertJsonFragment(['total' => 3]);

        $this->actingAs($this->parentUser)->get('/api/calendars?student_id=' . $studentA1->getKey())
            // These events seen from StudentA1 courses. Others are filtered out.
            ->assertJsonFragment(['total' => 1])
            ->assertJsonFragment(['summary' => 'Chemistry, StudentB, A1']);

        $this->actingAs($this->parentUser2)->get('/api/calendars')
            // Roles should be seen in attendees.
            ->assertJsonFragment(['role' => Course::ROLE_PRIMARY_TEACHER])
            ->assertJsonFragment(['role' => Course::ROLE_STUDENT])
            // Parent2 sees events where StudentB present.
            ->assertJsonFragment(['summary' => 'Chemistry, StudentB, A1'])
            ->assertJsonFragment(['summary' => 'Chemistry, StudentAB'])
            // This is not seen, there is no StudentB attendee.
            ->assertDontSeeText('"Chemistry, StudentA"');

        // This will validate Parents are seeing Courses for their Children.
        $this->actingAs($this->parentUser)->get('/api/courses')
            // These events due to StudentA present in both Courses.
            ->assertJsonFragment(['name' => 'Chemistry, StudentA'])
            ->assertJsonFragment(['name' => 'Chemistry, StudentAB'])
            // These events seen from StudentA1 courses.
            ->assertJsonFragment(['name' => 'Chemistry, StudentB, A1'])
            ->assertJsonFragment(['total' => 3]);

        // This Parent would see Courses for the given StudentA1. Others are filtered out.
        $this->actingAs($this->parentUser)->get('/api/courses?student_id=' . $studentA1->getKey())
            // These events seen from StudentA1 courses.
            ->assertJsonFragment(['name' => 'Chemistry, StudentB, A1'])
            ->assertJsonFragment(['total' => 1]);

        $this->actingAs($this->parentUser2)->get('/api/courses')
            // Parent2 sees Courses where StudentB present.
            ->assertJsonFragment(['name' => 'Chemistry, StudentB, A1'])
            ->assertJsonFragment(['name' => 'Chemistry, StudentAB'])
            ->assertJsonFragment(['total' => 2]);
    }
}
