<?php

namespace Tests\Functional\API;

use App\Http\ApiCodes;
use App\Models\Course;
use App\Models\Group;
use App\Models\School;
use App\Models\Term;
use App\Models\User;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\ApiTestCase;

class PermissionTest extends ApiTestCase
{
    /**
     * Retrieve Permissions list via API call.
     *
     * @return void
     * @throws \Throwable
     */
    public function testGetPermissions()
    {
        // User should have permission.index permission.
        $this->get('/api/permissions')
            ->assertJsonFragment(['message' => 'Unauthorized']);

        $this->actingAs($this->adminUser)->get('/api/permissions?per_page=50')
            ->assertJsonFragment(['name' => 'calendar.create']);
    }
}
