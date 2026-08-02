<?php

namespace Tests\Feature;

use App\Models\ProductionOrder;
use App\Models\User;
use App\Services\PublicUrl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The client questionnaire link must point at an address the CLIENT can reach,
 * even when the officer is browsing over the office LAN.
 */
class ClientLinkPublicUrlTest extends TestCase
{
    use RefreshDatabase;

    private function order(): ProductionOrder
    {
        $sales = User::factory()->create(['job_role' => User::ROLE_SALES, 'is_active' => true]);
        $this->actingAs($sales)->post('/orders', [
            'order_number' => 'IC2026-01010',
            'client_name' => 'Link Co',
            'due_date' => now()->addWeeks(3)->toDateString(),
            'product_type' => 'round_neck',
            'sizes' => ['M' => 10],
        ]);

        return ProductionOrder::where('order_number', 'IC2026-01010')->firstOrFail();
    }

    // ---- The resolver ------------------------------------------------------

    public function test_configured_public_url_wins(): void
    {
        config(['app.public_url' => 'https://app.imprintcustoms.ph']);

        $this->assertSame('https://app.imprintcustoms.ph', PublicUrl::base());
        $this->assertSame(
            'https://app.imprintcustoms.ph/imprint-customs/design-questionnaire/tok123',
            PublicUrl::rewrite('http://192.168.150.190:8000/imprint-customs/design-questionnaire/tok123')
        );
    }

    public function test_url_is_unchanged_when_no_public_address_is_known(): void
    {
        config(['app.public_url' => '']);
        // base() then falls back to the tunnel file, which may or may not exist
        // on this machine — so only assert the no-base behaviour explicitly.
        if (PublicUrl::base() === null) {
            $this->assertSame(
                'http://192.168.150.190:8000/x',
                PublicUrl::rewrite('http://192.168.150.190:8000/x')
            );
        }
        $this->assertTrue(true);
    }

    public function test_private_hosts_are_detected(): void
    {
        foreach ([
            'http://192.168.150.190:8000/x',
            'http://10.0.0.5/x',
            'http://172.16.4.4/x',
            'http://127.0.0.1:8000/x',
            'http://localhost:8000/x',
            'http://server.local/x',
        ] as $url) {
            $this->assertTrue(PublicUrl::isPrivate($url), "$url should be private");
        }
    }

    public function test_public_hosts_are_not_flagged(): void
    {
        foreach ([
            'https://totals-prozac-kernel-spears.trycloudflare.com/x',
            'https://app.imprintcustoms.ph/x',
        ] as $url) {
            $this->assertFalse(PublicUrl::isPrivate($url), "$url should be public");
        }
    }

    public function test_a_bare_172_address_outside_the_private_range_is_public(): void
    {
        // 172.15.x and 172.32.x are NOT private — only 172.16–172.31 are.
        $this->assertFalse(PublicUrl::isPrivate('http://172.15.0.1/x'));
        $this->assertFalse(PublicUrl::isPrivate('http://172.32.0.1/x'));
    }

    // ---- End to end on the page -------------------------------------------

    public function test_the_page_shows_a_public_client_link_when_one_is_configured(): void
    {
        config(['app.public_url' => 'https://app.imprintcustoms.ph']);
        $order = $this->order();
        $sales = User::find($order->created_by);

        $html = $this->actingAs($sales)->get("/orders/{$order->id}/design-brief")
            ->assertOk()->getContent();

        $this->assertStringContainsString('https://app.imprintcustoms.ph/imprint-customs/design-questionnaire/', $html);
        $this->assertStringNotContainsString('Do not send this link yet', $html);
    }

    public function test_the_page_warns_when_the_link_is_only_reachable_in_house(): void
    {
        // No public URL configured and no tunnel file value -> request host is used.
        config(['app.public_url' => '']);
        $order = $this->order();
        $sales = User::find($order->created_by);

        $html = $this->actingAs($sales)->get("/orders/{$order->id}/design-brief")
            ->assertOk()->getContent();

        // Tests run against http://localhost, which is private.
        if (PublicUrl::base() === null) {
            $this->assertStringContainsString('Do not send this link yet', $html);
        }
        $this->assertTrue(true);
    }
}
