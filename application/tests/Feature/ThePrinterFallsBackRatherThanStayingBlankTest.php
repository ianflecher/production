<?php

namespace Tests\Feature;

use App\Models\JobOrder;
use App\Models\ProductionOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A job order never reaches the artist with no printer on it.
 *
 * The Printer box is one of the seventeen that must be answered before a tech
 * pack can be submitted, and nothing ever filled it in. So a pack could reach
 * the artist with the box empty and the job stopped on a question nobody had
 * been asked. An imported pack made it worse: that page hides the whole
 * interactive sheet, so there was nowhere on it to answer at all.
 *
 * applyPrintTypeRouting() already did exactly this for the cutting route and
 * the fabric press - fill it when empty, leave it alone when the officer has
 * chosen - and the printer was the one routing field it skipped.
 *
 * The fallback is Atexco, the shop's workhorse. But it is asked of the print
 * type FIRST, so it is not blindly Atexco: an embroidery job resolves to
 * Embroidery, which matters, because sending an embroidered job to a printer
 * is the bug this narrowly avoids repeating.
 */
class ThePrinterFallsBackRatherThanStayingBlankTest extends TestCase
{
    use RefreshDatabase;

    private function order(array $jobOrderFields = []): ProductionOrder
    {
        $officer = User::factory()->create(['job_role' => User::ROLE_SALES, 'is_active' => true]);

        $order = ProductionOrder::create([
            'order_number' => 'IC2026-0'.random_int(1000, 9999),
            'customer_name' => 'Fallback Co',
            'product_type' => 'round_neck',
            'quantity' => 40,
            'due_date' => now()->addWeeks(2),
            'created_by' => $officer->id,
            'status' => 'active',
        ]);

        $order->jobOrder()->create(array_merge([
            'status' => 'draft',
            'created_by' => $officer->id,
        ], $jobOrderFields));

        return $order->fresh();
    }

    /* ---------------- what it resolves to ---------------- */

    /** No print type at all: the workhorse. */
    public function test_with_nothing_said_it_is_atexco(): void
    {
        $jo = new JobOrder(['print_type' => null]);

        $this->assertSame('atexco', $jo->defaultPrinter());
        $this->assertSame(JobOrder::PRINTER_FALLBACK, $jo->defaultPrinter());
    }

    /**
     * The one that matters. An embroidered job must NOT fall back to a
     * printer - that is the mistake this whole area has just been through.
     */
    public function test_an_embroidery_job_falls_back_to_embroidery(): void
    {
        $jo = new JobOrder(['print_type' => 'embroidery']);

        $this->assertSame('embroidery', $jo->defaultPrinter());
        $this->assertNotSame('atexco', $jo->defaultPrinter());
    }

    public function test_each_print_type_brings_its_own_printer(): void
    {
        $this->assertSame('dtf_printer', (new JobOrder(['print_type' => 'dtf']))->defaultPrinter());
        $this->assertSame('atexco', (new JobOrder(['print_type' => 'full_sublimation']))->defaultPrinter());
        $this->assertSame('epson_eco_solvent', (new JobOrder(['print_type' => 'eco_solvent']))->defaultPrinter());
    }

    /** Two live packs say "N/A" as a print type. Unrecognised, so: the fallback. */
    public function test_an_unrecognised_print_type_lands_on_the_fallback(): void
    {
        $this->assertSame('atexco', (new JobOrder(['print_type' => 'N/A']))->defaultPrinter());
    }

    /** Older job orders stored the LABEL rather than the key. */
    public function test_a_print_type_stored_as_a_label_still_resolves(): void
    {
        $this->assertSame('atexco', (new JobOrder(['print_type' => 'Full Sublimation']))->defaultPrinter());
    }

    /* ---------------- when it is applied ---------------- */

    public function test_an_empty_printer_is_filled_when_the_routing_runs(): void
    {
        $order = $this->order(['print_type' => 'full_sublimation']);

        $this->assertNull($order->jobOrder->printer);

        $order->applyPrintTypeRouting();

        $this->assertSame('atexco', $order->fresh()->jobOrder->printer);
    }

    public function test_an_embroidery_job_is_routed_to_embroidery_not_a_printer(): void
    {
        $order = $this->order(['print_type' => 'embroidery']);

        $order->applyPrintTypeRouting();

        $this->assertSame('embroidery', $order->fresh()->jobOrder->printer);
    }

    /**
     * The half the request turns on: it is a fallback, not a decision. What
     * the officer chose stays chosen, even where it disagrees with the print
     * type - they are the one looking at the job.
     */
    public function test_the_officers_own_choice_is_never_overwritten(): void
    {
        $order = $this->order(['print_type' => 'full_sublimation', 'printer' => 'dtf_printer']);

        $order->applyPrintTypeRouting();

        $this->assertSame('dtf_printer', $order->fresh()->jobOrder->printer);
    }

    public function test_it_does_not_overwrite_a_deliberate_embroidery_choice(): void
    {
        $order = $this->order(['print_type' => 'dtf', 'printer' => 'embroidery']);

        $order->applyPrintTypeRouting();

        $this->assertSame('embroidery', $order->fresh()->jobOrder->printer);
    }

    /* ---------------- and the pack can then be sent ---------------- */

    /**
     * The point of the whole thing: the box being unanswerable was what stood
     * between a job order and its artist.
     */
    public function test_a_job_order_is_ready_to_send_once_the_routing_has_run(): void
    {
        $order = $this->order(['print_type' => 'full_sublimation', 'fabric' => 'Cotton blend']);

        $this->assertFalse($order->jobOrder->isReadyToSend());

        $order->applyPrintTypeRouting();

        $this->assertTrue($order->fresh()->jobOrder->isReadyToSend());
    }

    /**
     * It must not reshuffle a job people are already stood at -
     * canEditRouting() is that guard, and it applies here as much as to the
     * cutting route beside it.
     */
    public function test_it_leaves_a_job_already_in_production_alone(): void
    {
        $order = $this->order(['print_type' => 'full_sublimation']);

        \App\Models\Task::create([
            'production_order_id' => $order->id,
            'department' => 'Cutting',
            'team' => User::JOB_PRODUCTION,
            'stage' => 5,
            'sequence' => 50,
            'status' => 'in_progress',
        ]);

        $this->assertFalse($order->fresh()->canEditRouting());

        $order->fresh()->applyPrintTypeRouting();

        $this->assertNull($order->fresh()->jobOrder->printer);
    }
}
