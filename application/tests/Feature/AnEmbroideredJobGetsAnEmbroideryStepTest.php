<?php

namespace Tests\Feature;

use App\Models\JobOrder;
use App\Models\ProductionOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Saying "Embroidery" in the Printer box gives the job an embroidery step.
 *
 * That box only learned to say Embroidery this morning, and nothing else in
 * the shop was listening. defaultFabricPress() asked the PRINT TYPE and only
 * the print type, so a job could be an embroidery job in its product type, in
 * its imported sheet, and in the box the officer had just answered — and
 * still carry needs_embroidery = 0 and no Embroidery step. The work then
 * appeared at no station and waited for nobody.
 *
 * IC2026-00009 is the one that showed it: "Embro Print Only", printer set to
 * embroidery, an imported sheet that says embroidery, no embroidery step.
 *
 * The step itself is added by syncEmbroideryStep() off needs_embroidery, and
 * that has always worked — nothing reached it.
 */
class AnEmbroideredJobGetsAnEmbroideryStepTest extends TestCase
{
    use RefreshDatabase;

    private function order(array $jobOrderFields = []): ProductionOrder
    {
        $officer = User::factory()->create(['job_role' => User::ROLE_SALES, 'is_active' => true]);

        $order = ProductionOrder::create([
            'order_number' => 'IC2026-0'.random_int(1000, 9999),
            'customer_name' => 'Embro Co',
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

    private function hasEmbroideryStep(ProductionOrder $order): bool
    {
        return $order->tasks()
            ->where('department', 'like', '%mbroider%')
            ->exists();
    }

    /* ---------------- what the box now means ---------------- */

    public function test_the_printer_box_saying_embroidery_sets_the_fabric_press(): void
    {
        $jo = new JobOrder(['print_type' => null, 'printer' => 'embroidery']);

        $this->assertSame('embroidery', $jo->defaultFabricPress());
    }

    /** The print type still answers it when it is the one that knows. */
    public function test_an_embroidery_print_type_still_answers_on_its_own(): void
    {
        $jo = new JobOrder(['print_type' => 'embroidery', 'printer' => null]);

        $this->assertSame('embroidery', $jo->defaultFabricPress());
    }

    /** And an ordinary job is not quietly turned into an embroidered one. */
    public function test_an_ordinary_job_gets_no_embroidery(): void
    {
        $jo = new JobOrder(['print_type' => 'full_sublimation', 'printer' => 'atexco']);

        $this->assertNotSame('embroidery', $jo->defaultFabricPress());
    }

    /* ---------------- and the step actually appears ---------------- */

    /**
     * The whole point. The officer answers one box and the job gains the
     * step, so the work reaches the embroidery station instead of waiting
     * for nobody.
     */
    public function test_the_job_gains_an_embroidery_step(): void
    {
        $order = $this->order(['printer' => 'embroidery']);

        $this->assertFalse($this->hasEmbroideryStep($order));

        $order->applyPrintTypeRouting();

        $order->refresh();

        $this->assertTrue((bool) $order->jobOrder->needs_embroidery);
        $this->assertSame('embroidery', $order->jobOrder->fabric_press);
        $this->assertTrue($this->hasEmbroideryStep($order),
            'the job said embroidery and still had no embroidery step');
    }

    public function test_the_step_runs_on_the_sewn_garment_not_at_the_printer(): void
    {
        $order = $this->order(['printer' => 'embroidery']);
        $order->applyPrintTypeRouting();

        $stages = $order->fresh()->tasks()
            ->where('department', 'like', '%mbroider%')
            ->pluck('stage')
            ->all();

        // Stage 7 is the sample's sewing line, 13 the batch's. Never stage 3,
        // which is the printer — embroidery runs on the sewn garment.
        $this->assertNotEmpty($stages);
        $this->assertNotContains(3, $stages);
    }

    /** A job that is not embroidered gains nothing. */
    public function test_an_ordinary_job_gains_no_step(): void
    {
        $order = $this->order(['print_type' => 'full_sublimation']);

        $order->applyPrintTypeRouting();

        $this->assertFalse((bool) $order->fresh()->jobOrder->needs_embroidery);
        $this->assertFalse($this->hasEmbroideryStep($order->fresh()));
    }

    /**
     * A press the officer chose themselves is still not overruled — this only
     * ever fills the fabric press when it is empty.
     */
    public function test_a_press_the_officer_chose_is_left_alone(): void
    {
        $order = $this->order(['printer' => 'embroidery', 'fabric_press' => 'roller_press']);

        $order->applyPrintTypeRouting();

        $this->assertSame('roller_press', $order->fresh()->jobOrder->fabric_press);
    }
}
