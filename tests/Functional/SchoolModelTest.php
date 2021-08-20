<?php

namespace Tests\Functional;

use App\Models\School;
use App\Models\User;
use Tests\FunctionalTestCase;

class SchoolModelTest extends FunctionalTestCase
{
    /**
     * Creates School model via factory.
     *
     * @return void
     */
    public function testSchoolModel()
    {
        $school = School::factory()->has(User::factory()->count(2))->create();

        $this->user->school()->associate($school)->save();

        $this->assertNotEmpty($school->id);
        $this->assertNotEmpty($school->name);

        $this->assertEquals($school->id, $this->user->school->id, 'Relation should be here');
        $this->assertEquals($school->users->first()->id, $this->user->id, 'Relation should be here');
        $this->assertCount(3, $school->users, 'there should be 3 users belonging to the School');
    }
}
