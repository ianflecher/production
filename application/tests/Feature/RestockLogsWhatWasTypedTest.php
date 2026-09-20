<?php

namespace Tests\Feature;

use App\Models\InventoryItem;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Restocking logs the amount that was typed.
 *
 * The dialog asks "Add to stock" and used to send a TOTAL: the browser read
 * the figure printed on the page, added what was typed, and the server
 * subtracted the real figure to get back to a movement. Any drift between the
 * page and the shelf landed in that difference.
 *
 * Ricky typed 150.5 kg of TASLAN H9 BLK against a page showing 239. The shelf
 * actually held 233. The sheet logged 156.5 kg in — neither the number he
 * typed nor the number he meant, and the stock ended 6 kg over.
 *
 * The amount is sent as an amount now, so a page a few minutes out of date
 * cannot change it.
 */
class RestockLogsWhatWasTypedTest extends TestCase
{
    use RefreshDatabase;

    private function keeper(): User
    {
        return User::factory()->create([
            'job_role' => User::JOB_RAW_MATERIALS_SUPERVISOR, 'is_active' => true,
        ]);
    }

    private function fabric(float $onTheShelf): InventoryItem
    {
        return InventoryItem::create([
            'name' => 'TASLAN H9 BLK',
            'category' => 'FABRIC',
            'kind' => InventoryItem::KIND_FABRIC,
            'unit' => 'KG',
            'quantity' => $onTheShelf,
            'beginning_stock' => $onTheShelf,
        ]);
    }

    private function restock(User $keeper, InventoryItem $item, array $fields): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($keeper)->post(route('inventory.update', $item), $fields + [
            'unit' => 'KG',
            'operator_name' => 'Ricky',
        ]);
    }

    /* ---------------- the number typed is the number logged ---------------- */

    public function test_a_decimal_amount_is_logged_exactly(): void
    {
        $keeper = $this->keeper();
        $item = $this->fabric(233);

        $this->restock($keeper, $item, ['add' => 150.5])->assertSessionHasNoErrors();

        $this->assertSame('383.50', $item->fresh()->quantity);

        $movement = StockMovement::where('inventory_item_id', $item->id)->latest('id')->first();

        $this->assertSame('150.50', $movement->quantity, 'the sheet logged something other than what was typed');
        $this->assertSame(StockMovement::IN, $movement->direction);
    }

    /**
     * The real one. The page said 239, the shelf held 233, and Ricky typed
     * 150.5 — which used to arrive as a total of 389.5 and log 156.5.
     */
    public function test_a_stale_page_cannot_change_the_amount(): void
    {
        $keeper = $this->keeper();
        $item = $this->fabric(233);

        // What the old dialog would have sent from a page showing 239.
        $this->restock($keeper, $item, ['add' => 150.5, 'quantity' => 389.5]);

        $movement = StockMovement::where('inventory_item_id', $item->id)->latest('id')->first();

        $this->assertSame('150.50', $movement->quantity);
        $this->assertSame('383.50', $item->fresh()->quantity, 'the shelf ended 6kg over');
    }

    /** Adding nothing, to change only the photo or the unit, logs nothing. */
    public function test_adding_nothing_records_no_movement(): void
    {
        $keeper = $this->keeper();
        $item = $this->fabric(233);

        $this->restock($keeper, $item, ['add' => 0])->assertSessionHasNoErrors();

        $this->assertSame(0, StockMovement::where('inventory_item_id', $item->id)->count());
        $this->assertSame('233.00', $item->fresh()->quantity);
    }

    /** A blank box is the same as adding nothing. */
    public function test_a_blank_amount_is_not_an_error(): void
    {
        $keeper = $this->keeper();
        $item = $this->fabric(233);

        $this->restock($keeper, $item, [])->assertSessionHasNoErrors();

        $this->assertSame('233.00', $item->fresh()->quantity);
    }

    /* ---------------- counting a shelf is still possible ---------------- */

    /**
     * Setting an absolute count still works for anything that sends one: the
     * difference is logged as the correction it is.
     */
    public function test_an_absolute_count_still_corrects_the_shelf(): void
    {
        $keeper = $this->keeper();
        $item = $this->fabric(233);

        $this->restock($keeper, $item, ['quantity' => 200]);

        $movement = StockMovement::where('inventory_item_id', $item->id)->latest('id')->first();

        $this->assertSame('200.00', $item->fresh()->quantity);
        $this->assertSame('33.00', $movement->quantity);
        $this->assertSame(StockMovement::OUT, $movement->direction);
    }

    /* ---------------- and the dialog sends an amount ---------------- */

    public function test_the_dialog_posts_the_amount_not_a_total(): void
    {
        $keeper = $this->keeper();
        $this->fabric(233);

        $html = $this->actingAs($keeper)->get(route('inventory.index'))->assertOk()->getContent();

        $this->assertStringContainsString('id="rmAdd" name="add"', $html);
        $this->assertStringNotContainsString('name="quantity" id="rmQty"', $html);
        $this->assertStringNotContainsString('current + Number(', $html);
    }
}
