<?php

namespace Tests\Feature;

use App\Models\ProductItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Delivered work is not a shortage.
 *
 * Finished goods are made to order, so every one of them reaches zero the day
 * the client collects it. The stock list kept those rows and counted them in a
 * red "N out of stock" badge — filing every completed job as a problem, on a
 * number that could only grow. What was actually out of stock was nothing.
 */
class DeliveredProductsLeaveTheStockListTest extends TestCase
{
    use RefreshDatabase;

    private function desk(): User
    {
        return User::factory()->create(['job_role' => 'Inventory', 'is_active' => true]);
    }

    /** A product received into stock, then handed to the client. */
    private function deliveredItem(string $name = 'IC2026-00055 — Round Neck'): ProductItem
    {
        $item = ProductItem::create(['name' => $name, 'unit' => 'pcs', 'quantity' => 0]);
        $item->recordMovement(38, 'received', 'From production', null, 'Ton Ton');
        $item->recordMovement(-38, 'released', 'Client collected', null, 'Rowena');

        return $item->fresh();
    }

    public function test_a_delivered_product_is_not_called_out_of_stock(): void
    {
        $this->deliveredItem();

        $this->actingAs($this->desk())->get('/products')
            ->assertOk()
            ->assertDontSee('out of stock')
            ->assertDontSee('OUT OF STOCK');
    }

    public function test_it_is_listed_as_handed_over_instead(): void
    {
        $this->deliveredItem();

        $this->actingAs($this->desk())->get('/products')
            ->assertOk()
            ->assertSee('Handed over to the client')
            ->assertSee('IC2026-00055 — Round Neck', false)
            // Who let it go and when — the answer to "did they ever get it?".
            ->assertSee('Rowena');
    }

    public function test_stock_on_hand_only_counts_what_is_on_the_shelf(): void
    {
        $this->deliveredItem();
        $onHand = ProductItem::create(['name' => 'Blank tees', 'unit' => 'pcs', 'quantity' => 0]);
        $onHand->recordMovement(12, 'received', null, null, 'Ton Ton');

        $page = $this->actingAs($this->desk())->get('/products')->assertOk();

        $this->assertSame(1, $page->viewData('totalCount'),
            'a delivered product is not stock on hand');
        $this->assertTrue($page->viewData('items')->contains(fn ($i) => $i->name === 'Blank tees'));
        $this->assertFalse($page->viewData('items')->contains(fn ($i) => str_contains($i->name, 'IC2026-00055')));
    }

    public function test_the_record_is_kept_not_deleted(): void
    {
        $item = $this->deliveredItem();

        $this->actingAs($this->desk())->get('/products')->assertOk();

        $this->assertDatabaseHas('product_items', ['id' => $item->id]);
        $this->assertSame(2, $item->movements()->count(),
            'both the receipt and the release stay on the record');
    }

    public function test_a_product_that_never_shipped_is_not_filed_as_handed_over(): void
    {
        // Zero because nothing was ever received, not because it was collected.
        ProductItem::create(['name' => 'Never made', 'unit' => 'pcs', 'quantity' => 0]);

        $page = $this->actingAs($this->desk())->get('/products')->assertOk();

        $this->assertFalse($page->viewData('handedOver')->contains(fn ($i) => $i->name === 'Never made'));
    }
}
