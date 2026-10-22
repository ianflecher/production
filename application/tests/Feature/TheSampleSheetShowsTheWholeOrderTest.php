<?php

namespace Tests\Feature;

use App\Models\OrderItem;
use App\Models\ProductionOrder;
use App\Models\TechPack;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The sample sheet describes the sample, and nothing else.
 *
 * It briefly carried the order's whole breakdown underneath the sample row.
 * That was a misread: the request was for the package's Production Details
 * page, which is the floor's copy of the job, not the Tech Pack. The sample
 * sheet and the batch sheet are two documents on purpose, and the order's
 * quantities belong to the one describing the batch.
 *
 * The heading now names which run the sheet is for, which is what the sheet
 * was actually missing - on a one-piece order both sheets read "1" and nothing
 * on the paper said which you were holding.
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

    /* ---------------- which sheet am I holding ---------------- */

    public function test_the_sample_sheet_says_it_is_the_sample(): void
    {
        $html = $this->sheet($this->order(), TechPack::PHASE_SAMPLE);

        $this->assertStringContainsString('SAMPLE', $html);
    }

    public function test_the_batch_sheet_says_it_is_mass_production(): void
    {
        $order = $this->order();

        // The page only shows the batch sheet once that row exists; without it
        // it falls back to the sample, which is deliberate - see phaseAsked().
        TechPack::create([
            'production_order_id' => $order->id,
            'phase' => TechPack::PHASE_MASSPROD,
        ]);

        $html = $this->sheet($order, TechPack::PHASE_MASSPROD);

        $this->assertStringContainsString('MASS PRODUCTION', $html);
    }

    /* ---------------- and what is on it ---------------- */

    /** One garment, in the one size the officer picks. */
    public function test_the_sample_sheet_describes_one_piece(): void
    {
        $html = $this->sheet($this->order(), TechPack::PHASE_SAMPLE);

        $this->assertStringContainsString('name="sample_sizes[]"', $html);
        $this->assertStringContainsString('<tr class="tp-ref-size-total"><td>Total</td><td>1</td></tr>', $html);
    }

    /** The order's breakdown is the batch sheet's business, not the sample's. */
    public function test_the_sample_sheet_does_not_carry_the_whole_order(): void
    {
        $html = $this->sheet($this->order(), TechPack::PHASE_SAMPLE);

        $this->assertStringNotContainsString('The whole order', $html);
        // and none of the order's own quantities are printed on it
        $this->assertStringNotContainsString('<tr><td>XL</td><td>18</td></tr>', $html);
    }

    /** The batch sheet still shows what is LEFT to sew. */
    public function test_the_batch_sheet_still_takes_the_sample_off(): void
    {
        $order = $this->order();

        $batch = collect($order->techPackOrNew(TechPack::PHASE_MASSPROD)->batchSizeList($order))
            ->pluck('quantity', 'size')->all();

        $this->assertSame(['S' => 19, 'M' => 34, 'L' => 39, 'XL' => 18], $batch);
        $this->assertSame(110, array_sum($batch));
    }
}
