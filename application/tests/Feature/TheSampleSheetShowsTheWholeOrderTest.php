<?php

namespace Tests\Feature;

use App\Models\OrderItem;
use App\Models\ProductionOrder;
use App\Models\TechPack;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The sample sheet shows the whole order too.
 *
 * A sample is one garment in one size, and the sheet says so - which is right
 * for the garment being cut and useless for the job it belongs to. An 830-piece
 * order across five sizes reached the floor as a sheet reading "M, 1, total 1",
 * and the people sewing it had no way to see the run coming behind it.
 *
 * So the order's own breakdown is printed underneath, read-only. The piece
 * being made is still the single one picked above; this is the job it is a
 * fitting for, not an instruction to cut it.
 *
 * The batch sheet is untouched: it still takes the sample's piece off, because
 * that garment has already been sewn and the batch is what is LEFT to make.
 */
class TheSampleSheetShowsTheWholeOrderTest extends TestCase
{
    use RefreshDatabase;

    /** 111 pieces across four sizes - the live shape of IC2026-00009. */
    private function order(): ProductionOrder
    {
        $officer = User::factory()->create(['job_role' => User::ROLE_SALES, 'is_active' => true]);

        $order = ProductionOrder::create([
            'order_number' => 'IC2026-0'.random_int(1000, 9999),
            'customer_name' => 'Imprint Customs X Selecta',
            'product_type' => 'round_neck',
            'quantity' => 111,
            'due_date' => now()->addWeeks(2),
            'created_by' => $officer->id,
            'status' => 'active',
        ]);

        foreach (['S' => 20, 'M' => 34, 'L' => 39, 'XL' => 18] as $size => $qty) {
            OrderItem::create([
                'production_order_id' => $order->id,
                'size' => $size,
                'quantity' => $qty,
            ]);
        }

        $order->jobOrder()->create([
            'status' => 'sent_to_artist', 'created_by' => $officer->id,
            'print_type' => 'dtf', 'printer' => 'dtf_printer',
        ]);

        return $order->fresh();
    }

    private function sheet(ProductionOrder $order, string $phase): string
    {
        return $this->actingAs(User::find($order->created_by))
            ->get(route('job-orders.edit', ['order' => $order, 'phase' => $phase]))
            ->assertOk()->getContent();
    }

    /* ---------------- the sample sheet ---------------- */

    /** Every size on the order, with the count the client asked for. */
    public function test_the_sample_sheet_lists_the_whole_order(): void
    {
        $html = $this->sheet($this->order(), TechPack::PHASE_SAMPLE);

        $this->assertStringContainsString('The whole order', $html);

        foreach (['S' => 20, 'M' => 34, 'L' => 39, 'XL' => 18] as $size => $qty) {
            $this->assertStringContainsString(
                '<tr><td>'.$size.'</td><td>'.$qty.'</td></tr>', $html,
                $size.' is not on the sample sheet'
            );
        }

        // And what they add up to, so nobody totals four numbers by hand.
        $this->assertStringContainsString('<td>111</td>', $html);
    }

    /** The garment being made is still one piece, in one size. */
    public function test_the_sample_itself_is_still_one_piece(): void
    {
        $html = $this->sheet($this->order(), TechPack::PHASE_SAMPLE);

        // The officer still picks which size it is sewn in...
        $this->assertStringContainsString('name="sample_sizes[]"', $html);
        // ...and it is one of them, not one of each.
        $this->assertStringContainsString('<tr class="tp-ref-size-total"><td>Total</td><td>1</td></tr>', $html);
    }

    /** Shown, not typed: a second place to enter it is a second place to be wrong. */
    public function test_the_order_breakdown_is_not_editable(): void
    {
        $html = $this->sheet($this->order(), TechPack::PHASE_SAMPLE);

        $whole = substr($html, strpos($html, 'The whole order'));
        $whole = substr($whole, 0, strpos($whole, '</table>'));

        $this->assertStringNotContainsString('<input', $whole);
        $this->assertStringNotContainsString('<select', $whole);
    }

    /* ---------------- the batch sheet is unchanged ---------------- */

    /** It still shows what is LEFT to sew, not what was ordered. */
    public function test_the_batch_sheet_still_takes_the_sample_off(): void
    {
        $order = $this->order();

        $batch = collect($order->techPackOrNew(TechPack::PHASE_MASSPROD)->batchSizeList($order))
            ->pluck('quantity', 'size')->all();

        // One garment was sewn as the sample, in the first size on the order.
        $this->assertSame(['S' => 19, 'M' => 34, 'L' => 39, 'XL' => 18], $batch);
        $this->assertSame(110, array_sum($batch));
    }
}
