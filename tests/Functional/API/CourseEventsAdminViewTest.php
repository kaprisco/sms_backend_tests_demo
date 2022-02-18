<?php

namespace Tests\Functional\API;

use App\Models\Course;
use App\Models\Term;
use App\Models\User;
use Carbon\Carbon;
use Tests\ApiTestCase;

class CourseEventsAdminViewTest extends ApiTestCase
{
    private Term $term;

    public function setUp(): void
    {
        parent::setUp();
        // Disable auditing from this point on
//        Course::disableAuditing();
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
    public function testViewOtherUsersCalendarEvents()
    {
        /** @var User $studentA */
        $studentA = User::factory(['name' => 'Student A'])->create()->assignRole(Course::ROLE_STUDENT);
        /** @var User $studentB */
        $studentB = User::factory(['name' => 'Student B'])->create()->assignRole(Course::ROLE_STUDENT);

        $this->actingAs($this->teacherUser1)->postJson('/api/courses', ['data' => ['attributes' => [
            'term_id' => $this->term->getKey(),
            'rrule' => "DTSTART=2030-01-01 10:00;FREQ=DAILY;COUNT=1",
            'name' => 'Chemistry, StudentA',
            'users' => [
                ['user_id' => $studentA->getKey(), 'course_role' => Course::ROLE_STUDENT],
            ]
        ]]])->assertSuccessful();

        $this->actingAs($this->teacherUser1)->postJson('/api/courses', ['data' => ['attributes' => [
            'term_id' => $this->term->getKey(),
            'rrule' => "DTSTART=2030-01-01 11:00;FREQ=DAILY;COUNT=1",
            'name' => 'Chemistry, StudentB',
            'users' => [
                ['user_id' => $studentB->getKey(), 'course_role' => Course::ROLE_STUDENT],
            ]
        ]]])->assertSuccessful();

        // Admin by default see all events.
        $this->actingAs($this->adminUser)->get('/api/calendars')
//            ->assertDontSeeText('"Chemistry, StudentA"')
//            ->assertDontSeeText('"Chemistry, StudentB"')
            ->assertJsonFragment(['total' => 2]);

        // for StudentA there is one event seen.
        $this->actingAs($this->adminUser)->get('/api/calendars?for_user_id=' . $studentA->getKey())
            ->assertJsonFragment(['summary' => 'Chemistry, StudentA'])
            ->assertDontSeeText('"Chemistry, StudentB"')
            ->assertJsonFragment(['total' => 1]);

        // for StudentB there is another event seen.
        $this->actingAs($this->adminUser)->get('/api/calendars?for_user_id=' . $studentB->getKey())
            ->assertJsonFragment(['summary' => 'Chemistry, StudentB'])
            ->assertDontSeeText('"Chemistry, StudentA"')
            ->assertJsonFragment(['total' => 1]);

        // For Teacher user both events would be seen.
        $this->actingAs($this->adminUser)->get('/api/calendars?for_user_id=' . $this->teacherUser1->getKey())
        ->assertJsonFragment(['summary' => 'Chemistry, StudentB'])
        ->assertJsonFragment(['summary' => 'Chemistry, StudentB'])
        ->assertJsonFragment(['total' => 2]);
    }
}
