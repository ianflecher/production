<?php

namespace Tests\Feature;

use App\Models\JobOrder;
use App\Models\ProductionOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The cap press only runs caps, so it is hidden on jobs that aren't caps. */
class CapPressVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private function orderFor(string $productType, string $number = 'IC2026-07070'): ProductionOrder
    {
        $sales = User::factory()->create(['job_role' => User::ROLE_SALES, 'is_active' => true]);

        // Caps aren't in the price list, so they arrive as a typed product.
        $isListed = array_key_exists($productType, \App\Services\PricingService::products());

        $this->actingAs($sales)->post('/orders', array_filter([
            'order_number' => $number,
            'client_name' => 'Cap Co',
            'client_last_name' => 'Cruz',
            'client_contact' => '0917-000-0000',
            'client_address' => 'Angeles City',
            'due_date' => now()->addWeeks(3)->toDateString(),
            'product_type' => $isListed ? $productType : '__other__',
            'product_type_custom' => $isListed ? null : $productType,
            'sizes' => ['M' => 10],
        ]));

        return ProductionOrder::where('order_number', $number)->firstOrFail();
    }

    public function test_cap_press_is_hidden_on_a_shirt_job(): void
    {
        $order = $this->orderFor('round_neck');

        $options = JobOrder::pressOptionsFor($order);

        $this->assertArrayNotHasKey('cap_press', $options);
        $this->assertArrayHasKey('heat_press', $options, 'the other presses stay');
        $this->assertArrayHasKey('embroidery', $options);
    }

    public function test_cap_press_is_offered_on_a_cap_job(): void
    {
        $order = $this->orderFor('Cap');

        $this->assertTrue(JobOrder::orderHasCap($order));
        $this->assertArrayHasKey('cap_press', JobOrder::pressOptionsFor($order));
    }

    public function test_a_cap_is_recognised_however_it_is_written(): void
    {
        foreach (['Cap', 'Trucker Cap', 'bucket cap', 'CAP'] as $i => $name) {
            $order = $this->orderFor($name, 'IC2026-707'.$i);
            $this->assertTrue(JobOrder::orderHasCap($order), "'$name' should count as a cap");
        }
    }

    public function test_an_existing_cap_press_choice_is_never_dropped(): void
    {
        // A shirt job that somehow already holds cap_press must keep the option,
        // otherwise the value would vanish from the dropdown and be lost on save.
        $order = $this->orderFor('round_neck');

        $options = JobOrder::pressOptionsFor($order, 'cap_press');

        $this->assertArrayHasKey('cap_press', $options);
    }

    public function test_the_production_page_does_not_offer_cap_press_for_a_shirt(): void
    {
        $order = $this->orderFor('round_neck');
        $sales = User::find($order->created_by);

        $html = $this->actingAs($sales)->get("/job-orders/{$order->id}/production")
            ->assertOk()->getContent();

        $this->assertStringNotContainsString('value="cap_press"', $html);
        $this->assertStringContainsString('value="heat_press"', $html);
    }

    public function test_the_production_page_offers_cap_press_for_a_cap(): void
    {
        $order = $this->orderFor('Trucker Cap');
        $sales = User::find($order->created_by);

        $this->actingAs($sales)->get("/job-orders/{$order->id}/production")
            ->assertOk()
            ->assertSee('value="cap_press"', false);
    }
}
