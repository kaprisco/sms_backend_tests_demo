<?php

namespace Tests;

use App\Models\Course;
use App\Models\School;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

class ApiTestCase extends TestCase
{
    use RefreshDatabase;

    /** @var \App\Models\User */
    protected $user;

    public $defaultHeaders = [
        'Accept' => 'application/json',
    ];

    /**
     * Additional cookies for the request.
     *
     * @var array
     */
    protected $defaultCookies = [];
    /**
     * Indicates whether cookies should be encrypted.
     *
     * @var bool
     */
    protected $encryptCookies = true;

    /**
     * Prepare a test DB data, create sample object would be used in all test methods.
     */
    protected function setUp(): void
    {
        // Call parent setUp which bootstraps laravel
        parent::setUp();
        // Setup database with User, Roles, and Permissions.
        (new DatabaseSeeder)->run();

        $this->user = User::factory([
            'name' => 'Tester',
            'email' => 'tester@gmail.com',
            'school_id' => School::first()->getKey()
        ])->create();

        setPermissionsTeamId(School::first()->getKey());

        $this->user->givePermissionTo([
            // alarms
            'alarm.create',
            'alarm.view',
            'alarm.delete',
        ]);
//        dd($this->user->getAllPermissions());
        $this->actingAs($this->user);

        $this->setupUsers();
    }

    /** @var User */
    protected User $teacherUser1;
    /** @var User */
    protected User $teacherUser2;
    /** @var User */
    protected User $parentUser;
    /** @var User */
    protected User $parentUser2;
    /** @var User */
    protected User $supervisorUser;

    protected User $adminUser;

    private function setupUsers()
    {
        /** @var User $teacherUser */
        $this->teacherUser1 = User::factory()->asTeacher()->create([
            'name' => 'Teacher A',
            'email' => 'teacherA@gmail.com',
            'school_id' => $this->user->school_id,
        ]);
//        $this->teacherUser1->assignRole(Course::ROLE_TEACHER);

        /** @var User $teacherUser */
        $this->teacherUser2 = User::factory()->asTeacher()->create([
            'name' => 'Teacher B',
            'email' => 'teacherB@gmail.com',
            'school_id' => $this->user->school_id,
        ]);
//        $this->teacherUser2->assignRole(Course::ROLE_TEACHER);

        /** @var User $parentUser */
        $this->parentUser = User::factory()->create([
            'name' => 'Parent A',
            'email' => 'parentA@gmail.com',
            'school_id' => $this->user->school_id,
        ]);
        $this->parentUser->assignRole(Course::ROLE_PARENT);
        // $this->parentUser->givePermissionTo(['meeting.parent_teacher.create', 'meeting.parent_teacher.view']);

        /** @var User $parentUser2 */
        $this->parentUser2 = User::factory()->create([
            'name' => 'Parent B',
            'email' => 'parentB@gmail.com',
            'school_id' => $this->user->school_id,
        ]);
        $this->parentUser2->assignRole(Course::ROLE_PARENT);

        /** @var User $parentUser2 */
        $this->adminUser = User::factory()->create([
            'name' => 'Admin',
            'email' => 'admin@gmail.com',
            'school_id' => $this->user->school_id,
        ]);
        $this->adminUser->assignRole(Course::ROLE_ADMIN);

        /** @var User $supervisorUser */
        $this->supervisorUser = User::factory()->create([
            'name' => 'SuperVisor',
            'email' => 'SuperVisor@gmail.com',
            'school_id' => $this->user->school_id,
        ]);
        $this->supervisorUser->assignRole('Supervisor');
        $this->supervisorUser->givePermissionTo(['meeting.parent_teacher.update', 'meeting.parent_teacher.view']);
    }

    /**
     * Define additional cookies to be sent with the request.
     *
     * @param array $cookies
     * @return $this
     */
    public function withCookies(array $cookies)
    {
        $this->defaultCookies = array_merge($this->defaultCookies, $cookies);

        return $this;
    }

    /**
     * Add a cookie to be sent with the request.
     *
     * @param string $name
     * @param string $value
     * @return $this
     */
    public function withCookie(string $name, string $value)
    {
        $this->defaultCookies[$name] = $value;

        return $this;
    }

    /**
     * Disable automatic encryption of cookie values.
     *
     * @return $this
     */
    public function disableCookieEncryption()
    {
        $this->encryptCookies = false;

        return $this;
    }

    public function get($uri, array $headers = [])
    {
        $cookies = $this->prepareCookiesForRequest();
        $server = $this->transformHeadersToServerVars($headers);

        return $this->call('GET', $uri, [], $cookies, [], $server);
    }

    public function post($uri, array $data = [], array $headers = [])
    {
        $server = $this->transformHeadersToServerVars($headers);
        $cookies = $this->prepareCookiesForRequest();

        return $this->call('POST', $uri, $data, $cookies, [], $server);
    }

    public function put($uri, array $data = [], array $headers = [])
    {
        $server = $this->transformHeadersToServerVars($headers);
        $cookies = $this->prepareCookiesForRequest();

        return $this->call('PUT', $uri, $data, $cookies, [], $server);
    }

    public function patch($uri, array $data = [], array $headers = [])
    {
        $server = $this->transformHeadersToServerVars($headers);
        $cookies = $this->prepareCookiesForRequest();

        return $this->call('PATCH', $uri, $data, $cookies, [], $server);
    }

    public function delete($uri, array $data = [], array $headers = [])
    {
        $server = $this->transformHeadersToServerVars($headers);
        $cookies = $this->prepareCookiesForRequest();

        return $this->call('DELETE', $uri, $data, $cookies, [], $server);
    }

    public function options($uri, array $data = [], array $headers = [])
    {
        $server = $this->transformHeadersToServerVars($headers);
        $cookies = $this->prepareCookiesForRequest();

        return $this->call('OPTIONS', $uri, $data, $cookies, [], $server);
    }

    /**
     * If enabled, encrypt cookie values for request.
     *
     * @return array
     */
    protected function prepareCookiesForRequest()
    {
        if (! $this->encryptCookies) {
            return $this->defaultCookies;
        }

        return collect($this->defaultCookies)->map(function ($value) {
            return encrypt($value, false);
        })->all();
    }
}
