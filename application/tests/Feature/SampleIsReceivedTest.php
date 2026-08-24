<?php

namespace Tests\Feature;

use App\Models\ProductionOrder;
use App\Models\ProductItem;
use App\Models\ProductMovement;
use App\Models\ProductReceipt;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Nothing enters finished goods without being received — the sample included.
 *
 * Approving the sample used to count the piece straight into stock. Stock then
 * said a garment was on the shelf, with a Release button beside it, while the
 * piece was still in somebody's hands on the floor.
 */
class SampleIsReceivedTest extends TestCase
{
    use RefreshDatabase;

    private function order(): ProductionOrder
    {
        $sales = User::factory()->create(['job_role' => User::ROLE_SALES, 'is_active' => true]);

        return ProductionOrder::create([
            'order_number' => 'IC2026-0'.random_int(1000, 9999), 'customer_name' => 'Sample Co',
            'product_type' => 'round_neck', 'quantity' => 55,
            'due_date' => now()->addWeek(), 'created_by' => $sales->id, 'status' => 'active',
        ]);
    }

    public function test_an_approved_sample_is_queued_rather_than_stocked(): void
    {
        $order = $this->order();

        $order->stockFirstSample();

        $receipt = ProductReceipt::where('production_order_id', $order->id)->first();

        $this->assertNotNull($receipt, 'the sample has to reach the receiving desk');
        $this->assertSame('pending', $receipt->status);
        $this->assertTrue((bool) $receipt->is_sample);
        $this->assertSame('1.00', $receipt->expected_quantity);

        // And nothing is on the shelf until somebody puts it there.
        $this->assertSame(0, ProductMovement::count());
        $this->assertSame(0.0, (float) (ProductItem::first()?->quantity ?? 0));
    }

    public function test_approving_twice_does_not_queue_two_pieces(): void
    {
        $order = $this->order();

        $order->stockFirstSample();
        $order->stockFirstSample();

        $this->assertSame(1, ProductReceipt::where('production_order_id', $order->id)->count());
    }

    public function test_receiving_it_is_what_puts_it_in_stock(): void
    {
        $order = $this->order();
        $order->stockFirstSample();

        $desk = User::factory()->create(['job_role' => 'Inventory', 'is_active' => true]);
        $receipt = ProductReceipt::where('production_order_id', $order->id)->firstOrFail();

        $this->actingAs($desk)
            ->post(route('products.receive', $receipt), ['operator_name' => 'Rowena'])
            ->assertRedirect();

        $this->assertSame(1.0, (float) ProductItem::first()->quantity);
        $this->assertSame('received', ProductMovement::first()->reason);
    }
}
