<?php

namespace Tests\Feature;

use App\Models\OrderItem;
use App\Models\ProductionOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Production details says how many to make.
 *
 * The page is the one the floor works from - press, cutting, raw materials,
 * back pocket - and it never said how much of it to produce or in which sizes.
 * A sheet headed PRODUCTION DETAILS that cannot answer "how many" sent the
 * people cutting and sewing to another page, or to somebody's memory.
 *
 * Every size the order has, whatever run the sheet is for. The floor needs the
 * shape of the whole job, not the arithmetic of what is left after the sample.
 */
class ProductionDetailsSaysHowManyTest extends TestCase
{
    use RefreshDatabase;

    /** The live shape of IC2026-00003: 12 pieces, L and XL. */
    private function order(): ProductionOrder
    {
        $officer = User::factory()->create(['job_role' => User::ROLE_SALES, 'is_active' => true]);

        $order = ProductionOrder::create([
            'order_number' => 'IC2026-0'.random_int(1000, 9999),
            'customer_name' => 'Sponsored - Mrc Team Lewy Ani Well',
            'product_type' => 'round_neck',
            'quantity' => 12,
            'due_date' => now()->addWeeks(2),
            'created_by' => $officer->id,
            'status' => 'active',
        ]);

        foreach (['L' => 6, 'XL' => 6] as $size => $qty) {
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

    private function page(ProductionOrder $order, User $who, ?string $for = 'production'): string
    {
        return $this->actingAs($who)
            ->get(route('orders.package', $order).($for ? '?for='.$for : ''))
            ->assertOk()->getContent();
    }

    /** Quality control opens this page; it is their copy of the job. */
    public function test_quality_control_sees_the_sizes_and_the_count(): void
    {
        $qc = User::factory()->create(['job_role' => 'quality control', 'is_active' => true]);

        $html = $this->page($this->order(), $qc);

        $this->assertStringContainsString('SIZE AND QUANTITY', $html);
        $this->assertStringContainsString('>L</td>', $html);
        $this->assertStringContainsString('>XL</td>', $html);
        $this->assertStringContainsString('12 PCS', $html);
    }

    /** And so does the whole package, with no scope asked for. */
    public function test_the_whole_package_carries_it_too(): void
    {
        $officer = User::factory()->create(['job_role' => User::ROLE_SALES, 'is_active' => true]);
        $order = $this->order();

        $html = $this->page($order, User::find($order->created_by), for: null);

        $this->assertStringContainsString('SIZE AND QUANTITY', $html);
        $this->assertStringContainsString('12 PCS', $html);
    }

    /* ---------------- the sample ---------------- */

    /** Its own block, with the size it is sewn in. */
    public function test_a_sampled_job_shows_the_sample_on_its_own(): void
    {
        $qc = User::factory()->create(['job_role' => 'quality control', 'is_active' => true]);

        $html = $this->page($this->order(), $qc);

        $this->assertStringContainsString('SAMPLE', $html);
        // One garment, in the first size on the order.
        $this->assertStringContainsString('1 PC<', $html);
    }

    /** And it is one OF the run, never one more than it. */
    public function test_the_sample_says_it_is_not_extra(): void
    {
        $qc = User::factory()->create(['job_role' => 'quality control', 'is_active' => true]);

        $html = $this->page($this->order(), $qc);

        $this->assertStringContainsString('NOT EXTRA', $html);
        // The run itself is untouched by it: still 12, not 11 and not 13.
        $this->assertStringContainsString('12 PCS', $html);
    }

    /**
     * The run after the sample is one short, and the page says so.
     *
     * It said 12 and showed a sample of 1, which is both true and useless to
     * somebody about to cut the run: with the sample already sewn, "make 12"
     * is thirteen garments on a twelve-piece job.
     */
    public function test_the_page_says_what_is_left_after_the_sample(): void
    {
        $qc = User::factory()->create(['job_role' => 'quality control', 'is_active' => true]);
        $order = $this->order();

        $html = $this->page($order, $qc);

        // 12 ordered, one sewn as the sample, 11 to go.
        $this->assertStringContainsString('11 PCS', $html);
        // And the size it came out of is named, so the cutter does not take 6.
        $this->assertStringContainsString('L 5', $html);
        $this->assertStringContainsString('XL 6', $html);
    }

    /** It agrees with the tech pack rather than doing its own sum. */
    public function test_it_agrees_with_the_tech_packs_batch_sheet(): void
    {
        $qc = User::factory()->create(['job_role' => 'quality control', 'is_active' => true]);
        $order = $this->order();

        $batch = collect($order->techPackOrNew(\App\Models\TechPack::PHASE_MASSPROD)->batchSizeList($order));

        $html = $this->page($order, $qc);

        $this->assertStringContainsString(number_format($batch->sum('quantity')).' PCS', $html);
    }

    /** A job that makes no sample says nothing about one. */
    public function test_a_mass_production_job_has_no_sample_block(): void
    {
        $qc = User::factory()->create(['job_role' => 'quality control', 'is_active' => true]);

        $order = $this->order();
        $order->update(['skip_sample' => true]);

        $html = $this->page($order->fresh(), $qc);

        // The run is still there...
        $this->assertStringContainsString('SIZE AND QUANTITY', $html);
        $this->assertStringContainsString('12 PCS', $html);
        // ...and nothing claims a sample is being made.
        $this->assertStringNotContainsString('SAMPLE', $html);
        $this->assertStringNotContainsString('NOT EXTRA', $html);
    }

    /** One piece is a piece, not pieces. */
    public function test_a_single_piece_is_not_pluralised(): void
    {
        $officer = User::factory()->create(['job_role' => User::ROLE_SALES, 'is_active' => true]);

        $order = ProductionOrder::create([
            'order_number' => 'IC2026-0'.random_int(1000, 9999),
            'customer_name' => 'One Off',
            'product_type' => 'round_neck',
            'quantity' => 1,
            'due_date' => now()->addWeeks(2),
            'created_by' => $officer->id,
            'status' => 'active',
        ]);

        OrderItem::create(['production_order_id' => $order->id, 'size' => 'M', 'quantity' => 1]);
        $order->jobOrder()->create(['status' => 'sent_to_artist', 'created_by' => $officer->id]);

        $html = $this->page($order->fresh(), $officer);

        $this->assertStringContainsString('1 PC<', $html);
    }
}
