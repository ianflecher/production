<?php

namespace Tests\Feature;

use App\Models\OrderDocument;
use App\Models\ProductionOrder;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A line added to an order turns up on everything that reports the order.
 *
 * An order is a list of lines — a size, a count, and sometimes a description of
 * its own. Three separate things read that list: the quotation the client is
 * given, the size list on the tech pack the floor works to, and the order's own
 * total. A line that reaches one of them and not the others is the worst kind
 * of fault, because each sheet looks right on its own and only the shop finds
 * out, at the counter or at the cutting table.
 */
class AnotherProductShowsOnEverySheetTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: User, 1: ProductionOrder} */
    private function orderWithOneLine(): array
    {
        $sales = User::factory()->create(['job_role' => User::ROLE_SALES, 'is_active' => true]);

        $order = ProductionOrder::create([
            'order_number' => 'IC2026-00077', 'customer_name' => 'Second Line Co',
            'product_type' => 'round_neck', 'quantity' => 10,
            'unit_price' => 300,
            'due_date' => now()->addWeeks(2), 'created_by' => $sales->id, 'status' => 'active',
        ]);

        $order->items()->create(['size' => 'M', 'quantity' => 10]);
        $order->jobOrder()->create(['status' => 'draft', 'created_by' => $sales->id]);

        return [$sales, $order->refresh()];
    }

    public function test_the_quotation_gains_a_line_for_it(): void
    {
        [, $order] = $this->orderWithOneLine();

        $before = OrderDocument::defaultsFor($order, OrderDocument::TYPE_PQ)['items'];
        $this->assertCount(1, $before);

        $order->items()->create(['size' => 'XL', 'quantity' => 5, 'description' => 'Hooded jacket']);

        $after = OrderDocument::defaultsFor($order->refresh(), OrderDocument::TYPE_PQ)['items'];

        $this->assertCount(2, $after, 'the second line never reached the quotation');

        $added = collect($after)->firstWhere('size', 'XL');

        $this->assertNotNull($added, 'the added line is not on the quotation');
        $this->assertSame(5, $added['quantity']);
        $this->assertSame('Hooded jacket', $added['description'], 'the line lost its own description');
    }

    public function test_a_line_with_no_description_is_still_named_on_the_quotation(): void
    {
        // Most lines carry none: they are the order's own product, and the
        // client should not be handed a blank row.
        [, $order] = $this->orderWithOneLine();

        $order->items()->create(['size' => 'L', 'quantity' => 4]);

        $line = collect(OrderDocument::defaultsFor($order->refresh(), OrderDocument::TYPE_PQ)['items'])
            ->firstWhere('size', 'L');

        $this->assertNotSame('', $line['description'], 'the client is quoted a nameless line');
    }

    public function test_the_size_list_on_the_pack_gains_it_too(): void
    {
        // The floor cuts to this list. A line the client is charged for and the
        // cutting table never sees is a short order.
        [$sales, $order] = $this->orderWithOneLine();

        $order->items()->create(['size' => 'XL', 'quantity' => 5]);
        $order->refresh()->recomputeTotal();

        Task::create([
            'production_order_id' => $order->id, 'department' => 'Final mockup',
            'sequence' => 2, 'stage' => 2, 'status' => 'complete', 'approved_at' => now(),
        ]);
        $order->techPack()->create(['design_name' => 'Second Line Tee']);

        $this->actingAs($sales)
            ->get("/orders/{$order->id}/job-order")
            ->assertOk()
            ->assertSee('XL')
            ->assertSeeInOrder(['XL', '5'], false);
    }

    public function test_the_sizes_stay_in_the_order_the_shop_reads_them(): void
    {
        // Added last, but it belongs between M and XL — a size list out of
        // order is counted wrong at a bench.
        [, $order] = $this->orderWithOneLine();

        $order->items()->create(['size' => 'XL', 'quantity' => 5]);
        $order->items()->create(['size' => 'S', 'quantity' => 2]);
        $order->items()->create(['size' => 'L', 'quantity' => 3]);

        $this->assertSame(
            ['S', 'M', 'L', 'XL'],
            $order->refresh()->itemsInSizeOrder()->pluck('size')->all()
        );
    }

    public function test_adding_one_on_the_order_form_counts_it_in_the_money(): void
    {
        // Through the form, because that is the only way a line is ever added:
        // the order's own quantity is written in the same save as its lines, so
        // the two cannot drift. Creating an item on its own is a thing only a
        // test can do, and it would prove nothing about the shop.
        [$sales, $order] = $this->orderWithOneLine();

        $this->actingAs($sales)
            ->post("/orders/{$order->id}", [
                'client_name' => 'Second', 'client_last_name' => 'Line',
                'client_contact' => '0917 555 0000',
                'client_office_address' => 'Cebu City',
                'client_delivery_address' => 'Cebu City',
                'due_date' => now()->addWeeks(2)->toDateString(),
                'product_type' => 'round_neck',
                // The line that was already there, plus the new one.
                'sizes' => ['M' => 10, 'XL' => 5],
                'unit_price_override' => 300,
            ])->assertRedirect()->assertSessionHasNoErrors();

        $order->refresh();

        $this->assertSame(15, (int) $order->quantity, 'the order still thinks it is ten pieces');
        $this->assertSame(4500.0, (float) $order->total_price, '15 × 300');

        // And it reaches the client's quotation from there.
        $quoted = collect(OrderDocument::defaultsFor($order, OrderDocument::TYPE_PQ)['items']);

        $this->assertSame(15, $quoted->sum('quantity'), 'the quotation is short of the order');
        $this->assertNotNull($quoted->firstWhere('size', 'XL'));
    }
}
