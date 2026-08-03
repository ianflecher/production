<?php

namespace Tests\Feature;

use App\Models\ProductionOrder;
use App\Models\ProductItem;
use App\Models\ProductReceipt;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Finished stock only ever enters the products inventory one way: an order
 * reaches its Inventory step, which queues a receipt, and the products desk
 * confirms it. If that chain breaks, stock silently never arrives — so it is
 * worth pinning down end to end.
 */
class ProductReceiveTest extends TestCase
{
    use RefreshDatabase;

    private function desk(): User
    {
        // canManageProducts() matches a job role naming inventory/products.
        return User::factory()->create(['job_role' => 'Inventory', 'is_active' => true]);
    }

    private function order(int $m = 10, int $l = 5): ProductionOrder
    {
        $sales = User::factory()->create(['job_role' => User::ROLE_SALES, 'is_active' => true]);

        $this->actingAs($sales)->post('/orders', [
            'order_number' => 'IC2026-08800',
            'client_name' => 'Stock Co',
            'due_date' => now()->addWeeks(2)->toDateString(),
            'product_type' => 'round_neck',
            'sizes' => ['M' => $m, 'L' => $l],
        ]);

        return ProductionOrder::where('order_number', 'IC2026-08800')->firstOrFail();
    }

    /** Open the Inventory step, which is what queues the receipts. */
    private function reachInventoryStep(ProductionOrder $order): void
    {
        $stage = $order->tasks()->where('department', 'Inventory')->value('stage');
        $this->assertNotNull($stage, 'the pipeline should contain an Inventory step');

        $order->unlockStage((int) $stage);
    }

    public function test_finishing_an_order_queues_a_receipt_for_the_products_desk(): void
    {
        $order = $this->order();
        $this->reachInventoryStep($order);

        $receipt = ProductReceipt::where('production_order_id', $order->id)->first();

        $this->assertNotNull($receipt, 'a finished order must queue a receipt');
        $this->assertSame('pending', $receipt->status);
        // Sizes merge into one product line: 10 + 5 = 15 pcs.
        $this->assertEqualsWithDelta(15.0, (float) $receipt->expected_quantity, 0.01);
        $this->assertStringContainsString($order->order_number, $receipt->name);
    }

    public function test_the_desk_sees_it_waiting(): void
    {
        $order = $this->order();
        $this->reachInventoryStep($order);

        $this->actingAs($this->desk())->get('/products')
            ->assertOk()
            ->assertSee($order->order_number);
    }

    public function test_receiving_it_puts_the_stock_into_products(): void
    {
        $order = $this->order();
        $this->reachInventoryStep($order);
        $receipt = ProductReceipt::where('production_order_id', $order->id)->firstOrFail();

        $this->actingAs($this->desk())
            ->post("/products/receipts/{$receipt->id}/receive", ['operator_name' => 'Ton Ton'])
            ->assertRedirect();

        $item = ProductItem::where('name', $receipt->name)->first();
        $this->assertNotNull($item, 'stock should now exist in the products inventory');
        $this->assertEqualsWithDelta(15.0, (float) $item->quantity, 0.01);
        $this->assertSame('received', $receipt->fresh()->status);
    }

    public function test_receiving_records_who_took_it_in(): void
    {
        $order = $this->order();
        $this->reachInventoryStep($order);
        $receipt = ProductReceipt::where('production_order_id', $order->id)->firstOrFail();

        $this->actingAs($this->desk())
            ->post("/products/receipts/{$receipt->id}/receive", ['operator_name' => 'Ton Ton']);

        $this->assertDatabaseHas('product_movements', ['product_item_id' => ProductItem::first()->id]);
    }

    public function test_the_receiver_must_give_their_name(): void
    {
        $order = $this->order();
        $this->reachInventoryStep($order);
        $receipt = ProductReceipt::where('production_order_id', $order->id)->firstOrFail();

        $this->actingAs($this->desk())
            ->post("/products/receipts/{$receipt->id}/receive", [])
            ->assertInvalid(['operator_name']);

        $this->assertSame('pending', $receipt->fresh()->status);
    }

    public function test_the_same_receipt_cannot_be_received_twice(): void
    {
        $order = $this->order();
        $this->reachInventoryStep($order);
        $receipt = ProductReceipt::where('production_order_id', $order->id)->firstOrFail();

        $this->actingAs($this->desk())
            ->post("/products/receipts/{$receipt->id}/receive", ['operator_name' => 'Ton Ton']);
        // A second confirmation must not double the stock.
        $this->actingAs($this->desk())
            ->post("/products/receipts/{$receipt->id}/receive", ['operator_name' => 'Ton Ton'])
            ->assertForbidden();

        $this->assertEqualsWithDelta(15.0, (float) ProductItem::first()->quantity, 0.01);
    }

    public function test_queueing_twice_does_not_duplicate_the_receipt(): void
    {
        $order = $this->order();
        $this->reachInventoryStep($order);
        $this->reachInventoryStep($order);   // e.g. the step re-opens

        $this->assertSame(1, ProductReceipt::where('production_order_id', $order->id)->count());
    }

    public function test_someone_outside_the_products_desk_cannot_receive(): void
    {
        $order = $this->order();
        $this->reachInventoryStep($order);
        $receipt = ProductReceipt::where('production_order_id', $order->id)->firstOrFail();

        $artist = User::factory()->create(['job_role' => User::JOB_ARTIST, 'is_active' => true]);

        $this->actingAs($artist)
            ->post("/products/receipts/{$receipt->id}/receive", ['operator_name' => 'Sneaky'])
            ->assertForbidden();

        $this->assertSame('pending', $receipt->fresh()->status);
    }
}
