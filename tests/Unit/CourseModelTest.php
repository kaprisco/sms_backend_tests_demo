<?php

namespace Tests\Unit;

use App\Models\Course;
use App\Models\School;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CourseModelTest extends TestCase
{
    use RefreshDatabase;

    protected User $teacherUser1;
    protected User $teacherUser2;

    protected User $student1;
    protected User $student2;

    public function setUp(): void
    {
        parent::setUp();
        $this->setupUsers();
    }

    private function setupUsers()
    {
        (new DatabaseSeeder)->run();
        $school = School::create(['name' => 'School A']);
        $this->teacherUser1 = User::factory()->create([
            'name' => 'Teacher A',
            'email' => 'teacherA@gmail.com',
            'school_id' => $school->getKey(),
        ]);
        $this->teacherUser1->assignRole(Course::ROLE_TEACHER);

        $this->teacherUser2 = User::factory()->create([
            'name' => 'Teacher B',
            'email' => 'teacherB@gmail.com',
            'school_id' => $school->getKey(),
        ]);
        $this->teacherUser2->assignRole(Course::ROLE_STUDENT);

        $this->student1 = User::factory()->create([
            'name' => 'Student A',
            'email' => 'studentA@gmail.com',
            'school_id' => $school->getKey(),
        ]);
        $this->student1->assignRole(Course::ROLE_STUDENT);

        $this->student2 = User::factory()->create([
            'name' => 'Student B',
            'email' => 'studentB@gmail.com',
            'school_id' => $school->getKey(),
        ]);
        $this->student2->assignRole(Course::ROLE_STUDENT);
    }

    /**
     * Authorized user should be automatically assigned as a primary teacher to the newly created Course.
     */
    public function testCourseCreateModel()
    {
        $this->actingAs($this->teacherUser1);
        $course = Course::factory(['name'=>'Chemistry'])->create();

        $this->assertCount(1, $course->teachers, 'Should be one primary teacher by default');
        $this->assertEquals(Course::ROLE_PRIMARY_TEACHER, $course->teachers->first()->getCourseRole());
    }

    /*
     * This will attach new Course to the specific Term.
     */
    public function testCourseTerm()
    {
        $course = Course::factory(['name'=>'Chemistry'])->forTerm(['name'=> 'Term A'])->create();
        $this->assertEquals('Term A', $course->term->name);
    }

    public function testCourseAddStudent()
    {
        $this->actingAs($this->teacherUser1);
        $course = Course::factory(['name'=>'Chemistry'])->create();

        // Should accept either Collection of Users
        $course->addStudents(User::factory()->count(2)->create());
        // or just User.
        $course->addStudents($student = User::factory(['name' => 'Dup'])->create());

        // Check for Duplicate addition.
        $course->addStudents($student);

        $this->assertCount(3, $course->students, 'Should be 3 students attached');
        $this->assertEquals(Course::ROLE_STUDENT, $course->students->first()->getCourseRole());
    }

    /**
     * @return void
     */
    public function testCourseModel()
    {
        $course1 = Course::factory(['name' => 'Chemistry'])
            ->hasAttached($this->teacherUser1, ['role' => Course::ROLE_PRIMARY_TEACHER], 'teachers')
            ->hasAttached($this->teacherUser2, ['role' => Course::ROLE_TEACHER], 'teachers')
            ->hasAttached($this->student1, ['role' => Course::ROLE_STUDENT], 'students')
            ->create();

        // There should be only 2 teachers, no students
        $this->assertCount(2, $course1->teachers);
        // There should be one student.
        $this->assertCount(1, $course1->students);
        /** @var User $teacher */
        foreach ($course1->teachers as $teacher) {
            $this->assertEquals(
                $teacher->name === 'Teacher A'
                    ? Course::ROLE_PRIMARY_TEACHER
                    : Course::ROLE_TEACHER,
                $teacher->getCourseRole()
            );
        }

        $course2 = Course::factory(['name'=>'Math'])
            ->hasAttached($this->teacherUser2, ['role' => Course::ROLE_PRIMARY_TEACHER], 'teachers')
            ->create();

        $this->assertCount(1, $course2->teachers);
        // There should be no students at that point.
        $this->assertCount(0, $course2->students);

        // Test reverse relationships.
        // Teacher B should see two Courses.
        $this->assertCount(2, $this->teacherUser2->courses);

        /** @var Course $course2 */
        foreach ($this->teacherUser2->courses as $course) {
            $this->assertEquals(
                $course->name === 'Math'
                    ? Course::ROLE_PRIMARY_TEACHER
                    : Course::ROLE_TEACHER,
                $course->getUserRole()
            );
        }

        // Teacher A have one Course he's the main teacher of.
        $this->assertCount(1, $this->teacherUser1->courses);
        $this->assertEquals(
            Course::ROLE_PRIMARY_TEACHER,
            $this->teacherUser1->courses->first()->getUserRole()
        );

        // Student A should have a Student role.
        $this->assertEquals(
            Course::ROLE_STUDENT,
            $course1->students->first()->getCourseRole()
        );

//        dump($this->student1->getRoleNames());
        $this->student1->delete();
        // Should be no more students.
        $this->assertCount(0, $course1->refresh()->students);
    }
}
