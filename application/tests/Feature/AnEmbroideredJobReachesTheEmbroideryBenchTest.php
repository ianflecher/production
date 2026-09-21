<?php

namespace Tests\Feature;

use App\Http\Controllers\StationController;
use App\Models\ProductionOrder;
use App\Models\User;
use App\Services\Stations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A job embroidered instead of printed reaches the embroidery bench.
 *
 * "Embro Print Only" has no printing in it, but it still carries a Printer
 * step and a Mass production step, because every job does. Both are bound to
 * the machine the job order names — an Atexco job must not appear on the DTF
 * board — and there is no printer_embroidery station: embroidery is kept out
 * of PRINTERS on purpose, because that list is what builds those stations.
 *
 * So the two steps matched no station at all. IC2026-00009 sat ready at stage
 * 3 for nobody, the stages behind it never unlocked, and the embroidery
 * account opened to an empty board waiting on a step that could not arrive.
 */
class AnEmbroideredJobReachesTheEmbroideryBenchTest extends TestCase
{
    use RefreshDatabase;

    private function orderPrintedBy(string $printer, array $steps = ['Printer']): ProductionOrder
    {
        $sales = User::factory()->create(['job_role' => User::ROLE_SALES, 'is_active' => true]);

        $this->actingAs($sales)->post('/orders', [
            'order_number' => 'IC2026-'.str_pad((string) random_int(10000, 99999), 5, '0'),
            'client_name' => 'Embro', 'client_last_name' => 'Client',
            'client_contact' => '0917-000-0000', 'client_address' => 'Angeles City',
            'due_date' => now()->addWeeks(2)->toDateString(),
            'product_type' => 'round_neck',
            'sizes' => ['M' => 10],
        ]);

        $order = ProductionOrder::latest('id')->firstOrFail();
        $order->jobOrder->update(['printer' => $printer]);

        // Release the steps this test is about.
        foreach ($steps as $department) {
            $order->tasks()->where('department', $department)->update(['status' => 'ready']);
        }

        return $order->fresh();
    }

    private function atStation(string $station): array
    {
        return StationController::eligibleOrders($station)->pluck('order_number')->all();
    }

    /* ---------------- the printing step ---------------- */

    public function test_an_embroidered_jobs_printing_step_is_at_the_embroidery_bench(): void
    {
        $order = $this->orderPrintedBy('embroidery');

        $this->assertContains($order->order_number, $this->atStation('embroidery'),
            'the embroidery bench could not see the job it is meant to run');
    }

    /** And at no printing machine, because no machine prints it. */
    public function test_it_is_at_no_printing_machine(): void
    {
        $order = $this->orderPrintedBy('embroidery');

        foreach (['printer_atexco', 'printer_epson', 'printer_dtf_printer'] as $station) {
            $this->assertNotContains($order->order_number, $this->atStation($station), $station.' was offered it');
        }
    }

    /* ---------------- and the batch run ---------------- */

    public function test_the_batch_run_is_at_the_embroidery_bench_too(): void
    {
        $order = $this->orderPrintedBy('embroidery', ['Mass production']);

        $this->assertContains($order->order_number, $this->atStation('embroidery'),
            'the batch would have stalled at stage 10 the same way');
    }

    /* ---------------- printed jobs are unchanged ---------------- */

    public function test_a_printed_job_stays_on_its_own_machine(): void
    {
        $order = $this->orderPrintedBy('atexco');

        $this->assertContains($order->order_number, $this->atStation('printer_atexco'));
        $this->assertNotContains($order->order_number, $this->atStation('printer_dtf_printer'));
        $this->assertNotContains($order->order_number, $this->atStation('embroidery'),
            'an Atexco job is not the embroidery bench\'s printing');
    }

    /* ---------------- embroidery as an ADD-ON is unchanged ---------------- */

    /**
     * The other way embroidery happens: a job printed normally that also wants
     * embroidering. That step is the bench's whatever machine printed the job,
     * so the machine rule must not swallow it.
     */
    public function test_an_add_on_embroidery_step_still_reaches_the_bench(): void
    {
        $order = $this->orderPrintedBy('atexco');
        $order->jobOrder->update(['needs_embroidery' => true]);
        $order->refresh()->rebuildPipeline([], $order->cutting_type);
        $order->tasks()->where('department', 'Embroidery')->update(['status' => 'ready']);

        $this->assertContains($order->fresh()->order_number, $this->atStation('embroidery'),
            'an Atexco job that also needs embroidering lost its embroidery step');
    }

    /* ---------------- and the bench is somebody's ---------------- */

    public function test_the_embroidery_role_staffs_that_bench(): void
    {
        $this->assertContains('embroidery', Stations::stationsByRole()['embroidery']);
        $this->assertSame('embroidery', Stations::printerFor('embroidery'));
        $this->assertContains('Printer', Stations::departments('embroidery'));
        $this->assertContains('Mass production', Stations::departments('embroidery'));
    }
}
