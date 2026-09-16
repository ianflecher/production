<?php

namespace Tests\Feature;

use App\Models\OrderItem;
use App\Models\ProductionOrder;
use App\Models\TechPack;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A job that skips the sample gets its real quantities on the sheet.
 *
 * IC2026-00009 is 111 pieces — S:20, M:34, L:39, XL:18 — and its tech pack
 * printed S/M/L/XL at one each, total 4. That sheet is what the floor cuts
 * and presses from, so sending it asked them to make four garments of a
 * hundred-and-eleven-piece job.
 *
 * The chain: the order sets skip_sample, so the steps are built without the
 * sample stages AND without the separate mass-production tech pack step —
 * deliberately, because "an order that skips the sample never had two
 * garments; its one sheet is the one it has been filling in all along".
 * Task::techPackPhase() then decides the phase by department NAME: anything
 * that is not the mass-production step is a sample. Such an order never has
 * that step, so its only sheet was called a sample and printed one of each.
 *
 * The phase itself is deliberately left alone. It is the storage key for the
 * row, and rewriting it would orphan every sheet already filled in — three
 * live orders have one. What changed is the question the sheet asks: not
 * "which row am I" but "does this job make a sample at all".
 */
class ASkippedSampleSheetShowsTheWholeJobTest extends TestCase
{
    use RefreshDatabase;

    /** The live shape of IC2026-00009. */
    private function order(bool $skipSample): ProductionOrder
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
            'skip_sample' => $skipSample,
        ]);

        foreach (['S' => 20, 'M' => 34, 'L' => 39, 'XL' => 18] as $size => $qty) {
            OrderItem::create([
                'production_order_id' => $order->id,
                'size' => $size,
                'quantity' => $qty,
            ]);
        }

        return $order->fresh();
    }

    /* ---------------- which run the sheet describes ---------------- */

    /** The bug itself. */
    public function test_a_skipped_sample_sheet_is_not_a_sample_run(): void
    {
        $order = $this->order(skipSample: true);
        $pack = $order->techPackOrNew(TechPack::PHASE_SAMPLE);

        // The row is still stored under the sample phase - that is its key,
        // and nothing about it moves.
        $this->assertTrue($pack->isSample());

        // But the job makes no sample, so the sheet is the batch sheet.
        $this->assertFalse($pack->showsTheSampleRun($order));
    }

    /** A job that DOES make a sample is untouched. */
    public function test_an_ordinary_job_still_has_a_sample_run(): void
    {
        $order = $this->order(skipSample: false);
        $pack = $order->techPackOrNew(TechPack::PHASE_SAMPLE);

        $this->assertTrue($pack->showsTheSampleRun($order));
    }

    /** And the mass-production sheet was never a sample run either way. */
    public function test_the_mass_production_sheet_is_never_a_sample_run(): void
    {
        $order = $this->order(skipSample: false);
        $pack = $order->techPackOrNew(TechPack::PHASE_MASSPROD);

        $this->assertFalse($pack->showsTheSampleRun($order));
    }

    /* ---------------- the numbers on it ---------------- */

    /**
     * The whole point: the sheet carries what the floor actually has to make.
     * batchSizeList() already took nothing off for a skipped sample, so the
     * right numbers were sitting there the entire time behind the wrong
     * branch.
     */
    public function test_the_sheet_carries_the_whole_job(): void
    {
        $order = $this->order(skipSample: true);
        $pack = $order->techPackOrNew(TechPack::PHASE_SAMPLE);

        $batch = collect($pack->batchSizeList($order))->pluck('quantity', 'size')->all();

        $this->assertSame(['S' => 20, 'M' => 34, 'L' => 39, 'XL' => 18], $batch);
        $this->assertSame(111, array_sum($batch));
    }

    /** A sampled job still takes its sample pieces off the batch. */
    public function test_a_sampled_job_still_loses_its_sample_pieces(): void
    {
        $order = $this->order(skipSample: false);
        $pack = $order->techPackOrNew(TechPack::PHASE_MASSPROD);

        $batch = collect($pack->batchSizeList($order))->pluck('quantity', 'size')->all();

        // One of each size was sewn as the sample.
        $this->assertSame(['S' => 19, 'M' => 33, 'L' => 38, 'XL' => 17], $batch);
        $this->assertSame(107, array_sum($batch));
    }

    /* ---------------- what is printed ---------------- */

    public function test_the_printed_sheet_shows_the_real_totals(): void
    {
        $order = $this->order(skipSample: true);

        $html = view('partials.tech-pack', [
            'order' => $order,
            'phase' => TechPack::PHASE_SAMPLE,
        ])->render();

        $this->assertStringContainsString('111', $html);

        // The sample table writes a hard "1" in every quantity cell and totals
        // the number of sizes. Four sizes, so the old sheet said 4.
        $this->assertStringNotContainsString('<td>Total</td><td>4</td>', $html);
    }

    /** It no longer calls a run that never happens a sample. */
    public function test_the_sheet_does_not_say_sample_on_a_job_without_one(): void
    {
        $order = $this->order(skipSample: true);

        $html = view('partials.tech-pack', [
            'order' => $order,
            'phase' => TechPack::PHASE_SAMPLE,
        ])->render();

        $this->assertStringNotContainsString('>Sample</div>', $html);
        $this->assertStringContainsString('>Flats</div>', $html);
    }

    /** And a job that does make one still says so. */
    public function test_a_sampled_job_still_says_sample(): void
    {
        $order = $this->order(skipSample: false);

        $html = view('partials.tech-pack', [
            'order' => $order,
            'phase' => TechPack::PHASE_SAMPLE,
        ])->render();

        $this->assertStringContainsString('>Sample</div>', $html);
    }
}
