<?php

namespace Tests\Functional\API;

use App\Http\ApiCodes;
use App\Models\School;
use App\Models\User;
use Tests\ApiTestCase;
use Tests\FunctionalTestCase;

class VersionTest extends ApiTestCase
{
    /**
     * Retrieve User information via API call.
     *
     * @return void
     * @throws \Throwable
     */
    public function testVersion()
    {
        $response = $this->get('/api/version');

        $this->assertStringContainsString('"name": "Schol\u00e3ris', $response->getContent());
        $response->assertJsonFragment(['env' => 'testing']);
    }
}
