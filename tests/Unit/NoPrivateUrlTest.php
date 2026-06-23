<?php

namespace Tests\Unit;

use App\Rules\NoPrivateUrl;
use Tests\TestCase;

/**
 * @group no-private-url
 */
class NoPrivateUrlTest extends TestCase
{
    private function getError(string $url): ?string
    {
        $error = null;
        (new NoPrivateUrl())->validate('url', $url, function (string $msg) use (&$error) {
            $error = $msg;
        });
        return $error;
    }

    public function test_localhost_hostname_is_rejected(): void
    {
        $this->assertNotNull($this->getError('http://localhost/'));
    }

    public function test_loopback_ip_is_rejected(): void
    {
        $this->assertNotNull($this->getError('http://127.0.0.1/'));
    }

    public function test_private_class_a_range_is_rejected(): void
    {
        $this->assertNotNull($this->getError('http://10.0.0.1/'));
    }

    public function test_private_class_b_range_is_rejected(): void
    {
        $this->assertNotNull($this->getError('http://172.16.0.1/'));
    }

    public function test_private_class_c_range_is_rejected(): void
    {
        $this->assertNotNull($this->getError('http://192.168.1.100/'));
    }

    public function test_public_ip_is_accepted(): void
    {
        // 1.1.1.1 = Cloudflare DNS, publicly routable
        $this->assertNull($this->getError('https://1.1.1.1/'));
    }

    public function test_url_without_valid_host_fails(): void
    {
        $this->assertNotNull($this->getError('not-a-url'));
    }

    public function test_unresolvable_host_is_allowed(): void
    {
        // DNS failures skip the check to avoid false positives
        $this->assertNull($this->getError('https://this-host-definitely-does-not-exist-xyzabc123.invalid/'));
    }

    public function test_rejection_message_is_informative(): void
    {
        $error = $this->getError('http://192.168.1.1/');
        $this->assertStringContainsString('private', strtolower($error));
    }
}
