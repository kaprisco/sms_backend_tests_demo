<?php

namespace Tests\Functional\API;

use App\Http\Controllers\API\Transformers\CourseTransformer;
use App\Models\Calendars\CalendarSimpleEvent;
use App\Models\Course;
use App\Models\Group;
use App\Models\Room;
use App\Models\Term;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Notifications\DatabaseNotification;
use Tests\ApiTestCase;

class GroupEventsTest extends ApiTestCase
{
    private Term $term;

    private Group $groupA;
    private Group $groupB;

    private User $student1;
    private User $student2;
    private User $student3;
    private User $student4;
    private User $student5;
    private User $student6;

    public function setUp(): void
    {
        parent::setUp();
        Course::truncate();

        $this->assertTrue($this->teacherUser1->can('course.create'));
        $this->actingAs($this->teacherUser1);

        $this->term = Term::factory([
            'start_at' => Carbon::create(2030)->startOfYear(),
            'end_at' => Carbon::create(2030)->startOfYear()->endOfMonth(),
        ])->create();

        /** @var User $student1 */
        $this->student1 = User::factory(['name' => 'Student1 Group1'])->create()->assignRole(Course::ROLE_STUDENT);
        $this->student2 = User::factory(['name' => 'Student2 Group1'])->create()->assignRole(Course::ROLE_STUDENT);

        /** @var Group $groupA */
        $this->groupA = Group::factory()->for(Term::factory())
            ->create([
                'name' => 'Group A',
                'role_filter' => Course::ROLE_STUDENT,
                'owner_id' => $this->user->getKey(),
                'school_id' => $this->user
            ]);
        $this->groupA->users()->sync([$this->student1->getKey(), $this->student2->getKey()]);
        $this->assertCount(2, $this->groupA->users);

        /** @var User $student1 */
        $this->student3 = User::factory(['name' => 'Student1 Group2'])->create()->assignRole(Course::ROLE_STUDENT);
        $this->student4 = User::factory(['name' => 'Student2 Group2'])->create()->assignRole(Course::ROLE_STUDENT);

        /** @var Group $groupA */
        $this->groupB = Group::factory()->for(Term::factory())
            ->create([
                'name' => 'Group B',
                'role_filter' => Course::ROLE_STUDENT,
                'owner_id' => $this->user->getKey(),
                'school_id' => $this->user
            ]);
        $this->groupB->users()->sync([$this->student3->getKey(), $this->student4->getKey()]);
        $this->assertCount(2, $this->groupA->users);

        /** @var User $student5 */
        $this->student5 = User::factory(['name' => 'Student A'])->create()->assignRole(Course::ROLE_STUDENT);
        $this->student6 = User::factory(['name' => 'Student B'])->create()->assignRole(Course::ROLE_STUDENT);
    }

    public function testCreateCourseGroups()
    {
        $this->postJson('/api/courses', [
            'data' => [
                'attributes' => [
                    'term_id' => $this->term->getKey(),
                    'rrule' => "DTSTART=" . now()->toDateString() . ";FREQ=DAILY;BYDAY=MO,WE",
                    'name' => 'Chemistry',
                    'users' => [
                        ['user_id' => $this->student5->getKey(), 'course_role' => Course::ROLE_STUDENT],
                        ['user_id' => $this->student6->getKey(), 'course_role' => Course::ROLE_STUDENT],
                        // This is duplicated Student which is a pert of Group B.
                        // Should be ignored.
                        ['user_id' => $this->student3->getKey(), 'course_role' => Course::ROLE_STUDENT],
                    ],
                    'groups' => [
                        ['group_id' => $this->groupA->getKey(), 'course_role' => Course::ROLE_STUDENT],
                        ['group_id' => $this->groupB->getKey(), 'course_role' => Course::ROLE_STUDENT],
                    ],
                ]
            ]
        ])
            ->assertJsonFragment(['type' => CourseTransformer::JSON_OBJ_TYPE])
            ->assertJsonFragment(['student_count' => 6]);

        // Test User of the Group can see these Calendar Events.
        $this->actingAs($this->student1)->get('/api/calendars?per_page=1&include=users')
            ->assertJsonFragment(['group_id' => $this->groupA->getKey()])
            ->assertJsonFragment(['group_id' => $this->groupB->getKey()])
            // Students from Group1
            ->assertJsonFragment(['name' => 'Student1 Group1'])
            ->assertJsonFragment(['name' => 'Student2 Group1'])
            // Students from Group2
            ->assertJsonFragment(['name' => 'Student1 Group2'])
            ->assertJsonFragment(['name' => 'Student2 Group2'])
            // Users
            ->assertJsonFragment(['name' => 'Student A'])
            ->assertJsonFragment(['name' => 'Student B'])
            ->assertJsonFragment(['name' => 'Teacher A']);
    }

    public function testCreateCourseGroup()
    {
        $response = $this->postJson('/api/courses?include=users,groups,groups.users', [
            'data' => [
                'attributes' => [
                    'term_id' => $this->term->getKey(),
                    'name' => 'Chemy',
                    'description' => '<h1>Chemistry</h1>',
                    // pass users array of students to be added
                    'users' => [
                        ['user_id' => $this->student5->getKey(), 'course_role' => Course::ROLE_STUDENT],
                        ['user_id' => $this->student6->getKey(), 'course_role' => Course::ROLE_STUDENT],
                        ['user_id' => $this->teacherUser1->getKey(), 'course_role' => Course::ROLE_TEACHER],
                    ],
                    'groups' => [
                        ['group_id' => $this->groupA->getKey(), 'course_role' => Course::ROLE_STUDENT],
                        ['group_id' => $this->groupB->getKey(), 'course_role' => Course::ROLE_STUDENT],
                    ],
                ]
            ]
        ])
            ->assertJsonFragment(['type' => CourseTransformer::JSON_OBJ_TYPE])
            // There should be 2 Students, 2 Student Groups w/ 2 per each.
            ->assertJsonFragment(['student_count' => 6])

            // Validates both Students has been added.
            ->assertJsonFragment(['name' => 'Student A'])
            ->assertJsonFragment(['name' => 'Student B'])
            ->assertJsonFragment(['course_role' => Course::ROLE_STUDENT])
            ->assertJsonFragment(['name' => 'Teacher A'])
            ->assertJsonFragment(['course_role' => Course::ROLE_TEACHER])
            // These are included via include=groups.users
            ->assertJsonFragment(['name' => 'Student1 Group1'])
            ->assertJsonFragment(['name' => 'Student2 Group1'])
            // These are included via include=groups.users
            ->assertJsonFragment(['name' => 'Student1 Group2'])
            ->assertJsonFragment(['name' => 'Student2 Group2']);

        // There should be relation to 6 Users having student role.
        $this->assertEquals(6, substr_count($response->getContent(), '"course_role": "student"'));

        // Participant of Group should get CourseAttendeeAdded notification.
        $this->actingAs($this->student1)->get('/api/notifications')
            ->assertJsonFragment(['description' => 'Course Chemy'])
            ->assertJsonFragment(['total' => 1]);
    }

    public function testAddCourseGroups()
    {
        $course = Course::factory(['name' => 'Chemistry'])
            ->hasAttached(User::factory(['name' => 'Student']), ['role' => Course::ROLE_STUDENT], 'students')
            ->create();

        $this->groupA->role_filter = null;
        $this->groupA->save();

        // We cannot add Group without filter to the Course.
        $this->postJson(
            "/api/courses/{$course->id}/add_students?include=users,groups,groups.users",
            [
                'groups' => [
                    ['group_id' => $this->groupA->getKey(), 'course_role' => Course::ROLE_STUDENT],
                ]
            ]
        )->assertJsonFragment(['title' => 'Group doesn\'t have Student or Teacher filter']);

        $this->groupA->role_filter = Course::ROLE_STUDENT;
        $this->groupA->save();

        $this->enableSqlDebug();
        // Add this Group to the Course.
        $this->postJson(
            "/api/courses/{$course->id}/add_students?include=users,groups,groups.users",
            [
                'users' => [

                ],
                'groups' => [
                    ['group_id' => $this->groupA->getKey(), 'course_role' => Course::ROLE_STUDENT],
                ]
            ]
        )->assertJsonFragment(['name' => 'Student1 Group1'])
            ->assertJsonFragment(['name' => 'Student2 Group1'])
            ->assertJsonFragment(['course_role' => Course::ROLE_STUDENT]);

        $this->assertCount(1, Course::all());

        // Delete Group from the Course.
        $this->postJson(
            "/api/courses/{$course->id}/delete_students?include=users,groups,groups.users",
            [
                'groups' => [
                    // Delete GroupA.
                    ['group_id' => $this->groupA->getKey()],
                ]
            ]
        )->assertJsonFragment(['name' => 'Student'])
            ->assertDontSeeText('Student1 Group1')
            ->assertDontSeeText('Student2 Group1')
            ->assertJsonFragment(['course_role' => Course::ROLE_STUDENT]);
    }

    /**
     * Test we can add Group to simple event.
     * @return void
     */
    public function testCreateGroupSimpleEvent()
    {
        $response = $this->actingAs($this->teacherUser1)->postJson(
            '/api/calendars/',
            [
                'data' => [
                    'attributes' => [
                        'reason' => 'Simple Event',
                        'summary' => 'Test summary',
                        'start_at' => now()->startOfHour(),
                        'end_at' => now()->endOfHour(),
                        'attendees' => [
                            ['user_id' => $this->parentUser->getKey()],
                            ['user_id' => $this->parentUser2->getKey()],
                            ['group_id' => $this->groupA->getKey()],
                        ],
                    ]
                ]
            ]
        );

        $response->assertJsonFragment(['summary' => 'Test summary'])
            ->assertJsonFragment(['reason' => 'Simple Event'])
            ->assertJsonFragment(['type' => 'simple'])
            // Users and Group' Users should be attached.
            ->assertJsonFragment(['user_id' => $this->parentUser->getKey()])
            ->assertJsonFragment(['user_id' => $this->parentUser2->getKey()])
            ->assertJsonFragment(['user_id' => $this->student1->getKey()])
            ->assertJsonFragment(['user_id' => $this->student2->getKey()]);

        // There should be two notifications, for attendees only.
        $this->assertCount(4, DatabaseNotification::all());

        // List Notification as a member of GroupA.
        $this->actingAs($this->student1)
            ->get("/api/notifications?type=interview_request,simple&" .
                "include=user:fields(name|last_name),user.roles:fields(name),calendar:fields(my_status)")
            ->assertJsonFragment(['title' => 'Event created by Teacher A <teacherA@gmail.com>'])
            ->assertJsonFragment(['total' => 1])
            // Organizer name included
            ->assertJsonFragment(['name' => 'Teacher A'])
            // Role included.
            ->assertJsonFragment(['name' => 'teacher'])
            // Attendee status included
            ->assertJsonFragment(['my_status' => 'unconfirmed'])
            ->assertJsonFragment(['type' => 'simple']);

        $event = CalendarSimpleEvent::first();

        $this->actingAs($this->teacherUser1)->delete('/api/calendars/' . $event->getKey())
            ->assertSuccessful();

        // After event deletion the notification should be gone, since there is no more sense in the notification.
        $this->actingAs($this->student1)
            ->get("/api/notifications")
            ->assertJsonFragment(['total' => 0]);
    }
}
