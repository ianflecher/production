<?php

namespace Tests\Feature;

use App\Models\JobOrder;
use App\Models\ProductionOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Step 4 of the production details: add-ons. Each add-on is matched to the
 * press that does it (automatic), but the press can still be overridden.
 */
class JobOrderAddonTest extends TestCase
{
    use RefreshDatabase;

    private function order(): ProductionOrder
    {
        $sales = User::factory()->create(['job_role' => User::ROLE_SALES, 'is_active' => true]);

        $this->actingAs($sales)->post('/orders', [
            'order_number' => 'IC2026-04444',
            'client_name' => 'Addon Co',
            'due_date' => now()->addWeeks(3)->toDateString(),
            'product_type' => 'round_neck',
            'sizes' => ['M' => 10],
        ]);

        return ProductionOrder::where('order_number', 'IC2026-04444')->firstOrFail();
    }

    private function save(ProductionOrder $order, array $fields): \Illuminate\Testing\TestResponse
    {
        $sales = User::find($order->created_by);

        return $this->actingAs($sales)->post("/job-orders/{$order->id}/production", array_merge([
            'fabric_press' => 'heat_press',   // Step 3, always required
        ], $fields));
    }

    public function test_each_addon_matches_its_own_press_automatically(): void
    {
        foreach ([
            'embroidery' => 'embroidery',
            'sublimated' => 'heat_press',
            'reflectorized' => 'roller_press',
        ] as $addon => $expectedPress) {
            $order = $this->order();

            $this->save($order, ['decoration_on' => 1, 'addon' => $addon])->assertRedirect();

            $jo = $order->fresh()->jobOrder;
            $this->assertSame($addon, $jo->addon, "$addon should be stored");
            $this->assertSame($expectedPress, $jo->press, "$addon should route to $expectedPress");

            // Reset for the next case.
            ProductionOrder::query()->forceDelete();
        }
    }

    public function test_the_matched_press_is_overridable(): void
    {
        $order = $this->order();

        // Embroidery normally routes to embroidery — override it to cap press.
        $this->save($order, [
            'decoration_on' => 1,
            'addon' => 'others',
            'addon_other' => 'Rubberized print',
            'press' => 'cap_press',
        ])->assertRedirect();

        $this->assertSame('cap_press', $order->fresh()->jobOrder->press);
    }

    public function test_others_requires_saying_what_it_is(): void
    {
        $order = $this->order();

        $this->save($order, ['decoration_on' => 1, 'addon' => 'others'])
            ->assertInvalid(['addon_other']);
    }

    public function test_others_stores_the_typed_description(): void
    {
        $order = $this->order();

        $this->save($order, [
            'decoration_on' => 1,
            'addon' => 'others',
            'addon_other' => 'Studs and piping',
            'press' => 'small_press',
        ])->assertRedirect();

        $jo = $order->fresh()->jobOrder;
        $this->assertSame('others', $jo->addon);
        $this->assertSame('Studs and piping', $jo->addon_other);
        $this->assertSame('Studs and piping', $jo->addonLabel());
    }

    public function test_an_addon_price_is_recorded(): void
    {
        $order = $this->order();

        $this->save($order, [
            'decoration_on' => 1,
            'addon' => 'embroidery',
            'addon_price' => 1250.75,
        ])->assertRedirect();

        $this->assertEqualsWithDelta(1250.75, (float) $order->fresh()->jobOrder->addon_price, 0.01);
    }

    public function test_leaving_the_tick_off_stores_no_addon(): void
    {
        $order = $this->order();

        // Even if an add-on is posted, an unticked box means no add-on.
        $this->save($order, ['addon' => 'embroidery', 'addon_price' => 500])->assertRedirect();

        $jo = $order->fresh()->jobOrder;
        $this->assertNull($jo->addon);
        $this->assertNull($jo->press);
        $this->assertNull($jo->addon_price);
    }

    public function test_the_fabric_press_is_still_required(): void
    {
        $order = $this->order();
        $sales = User::find($order->created_by);

        $this->actingAs($sales)
            ->post("/job-orders/{$order->id}/production", ['decoration_on' => 1, 'addon' => 'embroidery'])
            ->assertInvalid(['fabric_press']);
    }

    public function test_the_addon_press_map_is_what_the_shop_expects(): void
    {
        $this->assertSame('embroidery', JobOrder::pressForAddon('embroidery'));
        $this->assertSame('heat_press', JobOrder::pressForAddon('sublimated'));
        $this->assertSame('roller_press', JobOrder::pressForAddon('reflectorized'));
        $this->assertNull(JobOrder::pressForAddon('others'));
    }

    // ---- The money -------------------------------------------------------

    public function test_the_addon_price_is_added_to_the_order_total(): void
    {
        $order = $this->order();
        $before = (float) $order->total_price;

        $this->save($order, [
            'decoration_on' => 1,
            'addon' => 'embroidery',
            'addon_price' => 2000,
        ])->assertRedirect();

        $order->refresh()->load('jobOrder');
        $this->assertEqualsWithDelta($before + 2000, (float) $order->total_price, 0.01);
        $this->assertEqualsWithDelta(2000.0, $order->pricingBreakdown()['addon'], 0.01);
    }

    public function test_the_addon_increases_what_the_client_still_owes(): void
    {
        $order = $this->order();
        $balanceBefore = (float) $order->balance();

        $this->save($order, ['decoration_on' => 1, 'addon' => 'sublimated', 'addon_price' => 750]);

        $this->assertEqualsWithDelta($balanceBefore + 750, (float) $order->fresh()->balance(), 0.01);
    }

    public function test_removing_the_addon_takes_the_money_back_off(): void
    {
        $order = $this->order();
        $before = (float) $order->total_price;

        $this->save($order, ['decoration_on' => 1, 'addon' => 'embroidery', 'addon_price' => 2000]);
        $this->assertEqualsWithDelta($before + 2000, (float) $order->fresh()->total_price, 0.01);

        // Untick add-ons -> the charge goes away again.
        $this->save($order, []);
        $this->assertEqualsWithDelta($before, (float) $order->fresh()->total_price, 0.01);
    }

    public function test_the_addon_appears_as_a_line_on_the_quotation(): void
    {
        $order = $this->order();
        $this->save($order, [
            'decoration_on' => 1,
            'addon' => 'reflectorized',
            'addon_price' => 1200,
        ]);

        $defaults = \App\Models\OrderDocument::defaultsFor($order->fresh()->load('jobOrder'), 'pq');
        $line = collect($defaults['items'])->firstWhere('description', 'Reflectorized');

        $this->assertNotNull($line, 'the add-on should be its own quotation line');
        $this->assertEqualsWithDelta(1200.0, (float) $line['unit_price'], 0.01);
    }

    public function test_an_others_addon_uses_its_typed_name_on_the_quotation(): void
    {
        $order = $this->order();
        $this->save($order, [
            'decoration_on' => 1,
            'addon' => 'others',
            'addon_other' => 'Rubberized print',
            'press' => 'heat_press',
            'addon_price' => 900,
        ]);

        $defaults = \App\Models\OrderDocument::defaultsFor($order->fresh()->load('jobOrder'), 'pq');

        $this->assertNotNull(collect($defaults['items'])->firstWhere('description', 'Rubberized print'));
    }
}
