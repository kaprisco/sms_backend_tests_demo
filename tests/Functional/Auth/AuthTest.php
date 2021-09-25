<?php

namespace Tests\Functional\Auth;

use Tests\TestCase;

class AuthTest extends TestCase
{
    /**
     * Ensure our CSRF is responding.
     *
     * @return void
     * @throws \Throwable
     */
    public function testSanctumAuth()
    {
        $response = $this->get('/api/csrf-cookie', ['accept' => 'application/json']);
        $xsrf = $this->getCookie($response, 'XSRF-TOKEN', false);
        $this->assertNotNull($xsrf);
        $response->assertSee('csrf_token');
    }
}
