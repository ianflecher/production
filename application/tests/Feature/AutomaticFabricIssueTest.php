<?php

namespace Tests\Feature;

use App\Models\InventoryItem;
use App\Models\ProductionOrder;
use App\Models\User;
use App\Services\AutomaticFabricIssue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AutomaticFabricIssueTest extends TestCase
{
    use RefreshDatabase;

    public function test_combined_shortage_deducts_nothing_and_success_never_double_deducts(): void
    {
        $user = User::factory()->create();
        $order = ProductionOrder::create(['order_number' => 'AUTO-FABRIC', 'customer_name' => 'Test',
            'product_type' => 'Shirt', 'quantity' => 2, 'status' => 'active', 'created_by' => $user->id]);
        $task = $order->tasks()->create(['sequence' => 1, 'stage' => 3, 'department' => 'Raw materials', 'status' => 'ready']);
        $stock = InventoryItem::create(['name' => 'TEST FABRIC RED', 'kind' => InventoryItem::KIND_FABRIC,
            'unit' => 'kg', 'quantity' => 10]);
        foreach (['S', 'L'] as $size) {
            $order->materialRequests()->create(['material' => $stock->name, 'kind' => InventoryItem::KIND_FABRIC,
                'size' => $size, 'status' => 'pending', 'requested_quantity' => 6]);
        }
        $order->materialRequests()->create(['material' => 'Ready-made cap', 'kind' => InventoryItem::KIND_READY_MADE,
            'status' => 'pending', 'requested_quantity' => 1]);
        InventoryItem::forgetShelves();
        $this->assertSame(0, AutomaticFabricIssue::run($order));
        $this->assertEquals(10, $stock->fresh()->quantity);
        $stock->update(['quantity' => 20]);
        $this->assertSame(2, AutomaticFabricIssue::run($order));
        $this->assertEquals(8, $stock->fresh()->quantity);
        $this->assertSame('ready', $task->fresh()->status);
        $this->assertSame(0, AutomaticFabricIssue::run($order));
        $this->assertEquals(8, $stock->fresh()->quantity);
    }
}
