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
        $response = $this->get('/sanctum/csrf-cookie');
        $xsrf = $this->getCookie($response, 'XSRF-TOKEN', false);
        $this->assertNotNull($xsrf);
    }
}
