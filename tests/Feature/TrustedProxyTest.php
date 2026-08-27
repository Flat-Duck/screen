<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class TrustedProxyTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Guards the throttle-key correctness described in bootstrap/app.php: behind a trusted
     * proxy the client IP must come from X-Forwarded-For, not the proxy's own address.
     */
    public function test_client_ip_is_taken_from_forwarded_header_for_a_trusted_proxy(): void
    {
        $this->app['request']->setTrustedProxies(['192.0.2.10'], Request::HEADER_X_FORWARDED_FOR);
        $request = Request::create('/up', 'GET', server: ['REMOTE_ADDR' => '192.0.2.10']);
        $request->headers->set('X-Forwarded-For', '203.0.113.55');
        Request::setTrustedProxies(['192.0.2.10'], Request::HEADER_X_FORWARDED_FOR);

        $this->assertSame('203.0.113.55', $request->getClientIp());
    }

    public function test_forwarded_header_is_ignored_from_an_untrusted_source(): void
    {
        Request::setTrustedProxies(['192.0.2.10'], Request::HEADER_X_FORWARDED_FOR);
        $request = Request::create('/up', 'GET', server: ['REMOTE_ADDR' => '198.51.100.9']);
        $request->headers->set('X-Forwarded-For', '203.0.113.55');

        $this->assertSame('198.51.100.9', $request->getClientIp(), 'A spoofed XFF from an untrusted peer must be ignored.');
    }
}
