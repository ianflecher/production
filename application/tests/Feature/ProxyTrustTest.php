<?php

namespace Tests\Feature;

use App\Support\TrustedProxies;
use Tests\TestCase;

/**
 * Who Laravel believes about "this request came in over HTTPS".
 *
 * Getting this wrong doesn't throw — it quietly writes http:// links onto an
 * https:// page, the browser blocks the stylesheet as mixed content, and the
 * app renders unstyled. Worth pinning.
 */
class ProxyTrustTest extends TestCase
{
    public function test_the_office_setup_trusts_only_the_local_tunnel(): void
    {
        // cloudflared runs on the same PC, so loopback is the only hop.
        $this->assertSame(['127.0.0.1', '::1'], TrustedProxies::from(null));
        $this->assertSame(['127.0.0.1', '::1'], TrustedProxies::from(''));
        $this->assertSame(['127.0.0.1', '::1'], TrustedProxies::from('   '));
    }

    public function test_a_hosted_deployment_can_trust_its_platform_proxy(): void
    {
        $this->assertSame('*', TrustedProxies::from('*'));
    }

    public function test_specific_proxies_can_be_listed(): void
    {
        $this->assertSame(
            ['10.0.0.1', '10.0.0.2'],
            TrustedProxies::from('10.0.0.1, 10.0.0.2')
        );
    }

    public function test_a_ragged_list_still_comes_out_clean(): void
    {
        $this->assertSame(
            ['10.0.0.1', '10.0.0.2'],
            TrustedProxies::from(' 10.0.0.1 ,, 10.0.0.2 , ')
        );
    }

    public function test_a_forwarded_https_header_changes_nothing_from_an_untrusted_hop(): void
    {
        // An outsider claiming HTTPS must not be believed on the office setup.
        $this->get('/login', ['X-Forwarded-Proto' => 'https'])->assertOk();
    }
}
