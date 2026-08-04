<?php

namespace Tests\Feature;

use App\Models\ProductionOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The client design questionnaire is the ONLY route in the app reachable
 * without logging in. The random brief_token in the URL is the credential,
 * the link is single-use, and it expires — all of that is security-relevant.
 */
class PublicClientBriefTest extends TestCase
{
    use RefreshDatabase;

    private function orderWithLink(): ProductionOrder
    {
        $sales = User::factory()->create(['job_role' => User::ROLE_SALES, 'is_active' => true]);

        $this->actingAs($sales)->post('/orders', [
            'order_number' => 'IC2026-05050',
            'client_name' => 'Public Co',
            'client_last_name' => 'Cruz',
            'client_contact' => '0917-000-0000',
            'client_office_address' => 'Angeles City',
            'client_delivery_address' => 'Angeles City',
            'due_date' => now()->addWeeks(3)->toDateString(),
            'product_type' => 'round_neck',
            'sizes' => ['M' => 10],
        ]);

        $order = ProductionOrder::where('order_number', 'IC2026-05050')->firstOrFail();
        $order->regenerateBriefLink();

        // Log the officer back out — these tests are about the public link.
        $this->post('/logout');

        return $order->fresh();
    }

    private function url(ProductionOrder $order): string
    {
        return "/imprint-customs/design-questionnaire/{$order->brief_token}";
    }

    public function test_a_guest_with_the_token_can_open_the_questionnaire(): void
    {
        $order = $this->orderWithLink();

        $this->assertNotEmpty($order->brief_token, 'order should have a share token');
        $this->get($this->url($order))->assertOk();
    }

    public function test_a_guessed_token_is_rejected(): void
    {
        $this->orderWithLink();

        $this->get('/imprint-customs/design-questionnaire/not-a-real-token')->assertNotFound();
    }

    public function test_the_order_id_cannot_be_used_in_place_of_the_token(): void
    {
        $order = $this->orderWithLink();

        // Binding is by brief_token, so a simple sequential id must not work.
        $this->get("/imprint-customs/design-questionnaire/{$order->id}")->assertNotFound();
    }

    public function test_a_guest_can_submit_answers(): void
    {
        $order = $this->orderWithLink();

        $this->post($this->url($order), [
            'brief' => ['style' => 'Minimal, navy and white.'],
        ])->assertRedirect();

        $this->assertNotNull(
            $order->fresh()->jobOrder->client_brief_submitted_at,
            'submission should be recorded'
        );
    }

    public function test_the_link_is_single_use(): void
    {
        $order = $this->orderWithLink();

        $this->post($this->url($order), ['brief' => ['style' => 'First answer.']])->assertRedirect();

        // A second submission on the same link must be refused (410 Gone).
        $this->post($this->url($order), ['brief' => ['style' => 'Sneaky second answer.']])
            ->assertStatus(410);
    }

    public function test_an_expired_link_cannot_be_submitted(): void
    {
        $order = $this->orderWithLink();
        $order->update(['brief_expires_at' => now()->subDay()]);

        $this->post($this->url($order), ['brief' => ['style' => 'Too late.']])
            ->assertStatus(410);

        $this->assertNull($order->fresh()->jobOrder->client_brief_submitted_at);
    }

    public function test_the_public_link_does_not_grant_access_to_the_rest_of_the_app(): void
    {
        $order = $this->orderWithLink();

        // Opening the public page must not create a signed-in session.
        $this->get($this->url($order))->assertOk();

        $this->get('/orders')->assertRedirect('/login');
        $this->get('/dashboard')->assertRedirect('/login');
        $this->get("/orders/{$order->id}")->assertRedirect('/login');
    }
}
