<?php

namespace Tests\Feature;

use App\Models\InventoryItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Finding the materials that have run out.
 *
 * The page always said HOW MANY were out and drew those rows in red. On a
 * sheet of hundreds, shown a page at a time, that left the desk scrolling for
 * red rows to answer "so what do I need to order?" — the count named a problem
 * it gave you no way to look at.
 */
class RawMaterialsOutOfStockTest extends TestCase
{
    use RefreshDatabase;

    private function desk(): User
    {
        return User::factory()->create(['job_role' => 'Raw materials', 'is_active' => true]);
    }

    private function material(string $name, float $qty, string $category = 'COTTON SHIRT'): InventoryItem
    {
        return InventoryItem::create([
            'name' => $name, 'unit' => 'pcs', 'quantity' => $qty, 'category' => $category,
        ]);
    }

    public function test_the_out_of_stock_filter_shows_only_what_has_run_out(): void
    {
        $this->material('Aircool navy', 0);
        $this->material('Dri-fit white', 40);

        $items = $this->actingAs($this->desk())->get('/inventory?stock=out')
            ->assertOk()
            ->viewData('items');

        $this->assertCount(1, $items);
        $this->assertSame('Aircool navy', $items->first()->name);
    }

    public function test_in_stock_only_is_the_other_half_of_the_sheet(): void
    {
        $this->material('Aircool navy', 0);
        $this->material('Dri-fit white', 40);

        $items = $this->actingAs($this->desk())->get('/inventory?stock=in')
            ->assertOk()
            ->viewData('items');

        $this->assertCount(1, $items);
        $this->assertSame('Dri-fit white', $items->first()->name);
    }

    public function test_nothing_is_hidden_without_the_filter(): void
    {
        $this->material('Aircool navy', 0);
        $this->material('Dri-fit white', 40);

        $this->assertCount(2,
            $this->actingAs($this->desk())->get('/inventory')->viewData('items')
        );
    }

    public function test_the_count_links_to_the_rows_it_is_counting(): void
    {
        $this->material('Aircool navy', 0);

        $this->actingAs($this->desk())->get('/inventory')
            ->assertOk()
            ->assertSee('stock=out', false);
    }

    public function test_it_narrows_within_a_category_rather_than_replacing_it(): void
    {
        // "What bond paper have I run out of" — not "show me everything that is out".
        $this->material('Aircool navy', 0);
        $this->material('Bond paper A4', 0, 'BOND PAPER HARD COPY');
        $this->material('Bond paper A3', 60, 'BOND PAPER HARD COPY');

        $items = $this->actingAs($this->desk())
            ->get('/inventory?stock=out&category='.urlencode('BOND PAPER HARD COPY'))
            ->assertOk()
            ->viewData('items');

        $this->assertCount(1, $items);
        $this->assertSame('Bond paper A4', $items->first()->name);
    }

    public function test_searching_does_not_throw_the_filter_away(): void
    {
        // The filter is a real form field, not just a link, so the next search
        // keeps it instead of quietly showing everything again.
        $this->material('Aircool navy', 0);
        $this->material('Aircool red', 25);

        $items = $this->actingAs($this->desk())->get('/inventory?stock=out&q=Aircool')
            ->assertOk()
            ->viewData('items');

        $this->assertCount(1, $items);
        $this->assertSame('Aircool navy', $items->first()->name);
    }

    public function test_a_nonsense_filter_is_ignored_rather_than_obeyed(): void
    {
        $this->material('Aircool navy', 0);
        $this->material('Dri-fit white', 40);

        $page = $this->actingAs($this->desk())->get('/inventory?stock=banana')->assertOk();

        $this->assertSame('', $page->viewData('stock'));
        $this->assertCount(2, $page->viewData('items'));
    }
}
