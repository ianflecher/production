<?php

namespace Tests\Feature;

use App\Models\JobOrder;
use App\Models\ProductionOrder;
use App\Models\StationSession;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The sewing and QC parts of the job order sheet are filled at the station.
 *
 * They were on the account officer's form, which asked them — weeks before the
 * garment existed — which sewer would run the flatbed and what the checker
 * would find. Nobody can answer that, so the boxes printed blank. The people
 * who know are the ones holding the garment, and they answer when they close
 * their step.
 */
class StationFillsTheSheetTest extends TestCase
{
    use RefreshDatabase;

    private function orderAtSewing(): array
    {
        $sales = User::factory()->create(['job_role' => User::ROLE_SALES, 'is_active' => true]);
        $sewer = User::factory()->create(['job_role' => 'Sewing', 'is_active' => true]);

        $order = ProductionOrder::create([
            'order_number' => 'IC2026-0'.random_int(1000, 9999),
            'customer_name' => 'Seam Co',
            'product_type' => 'round_neck',
            'quantity' => 20,
            'due_date' => now()->addWeek(),
            'created_by' => $sales->id,
            'status' => 'active',
        ]);

        $order->jobOrder()->create(['status' => 'draft', 'created_by' => $sales->id]);

        Task::create([
            'production_order_id' => $order->id,
            'department' => 'Sewing',
            'sequence' => 12,
            'stage' => 6,
            'status' => 'ready',
        ]);

        return [$sewer, $order->fresh()];
    }

    /** Put an operator on a station, running this order. */
    private function runningOn(User $who, string $station, ProductionOrder $order): StationSession
    {
        $this->actingAs($who)->post('/stations/start', [
            'station' => $station,
            'operator_name' => 'Marites Bautista',
            'production_order_id' => $order->id,
        ]);

        return StationSession::where('station', $station)->whereNull('ended_at')->firstOrFail();
    }

    public function test_the_sewer_writes_the_seam_record_when_they_finish(): void
    {
        [$sewer, $order] = $this->orderAtSewing();
        $session = $this->runningOn($sewer, 'sewing_1', $order);

        $this->actingAs($sewer)->post("/station-sessions/{$session->id}/end", [
            'end_reason' => 'done',
            'sheet' => [
                'neckbond_sewer' => 'Marites Bautista',
                'neckbond_thread' => 'TC-220 navy',
                'flatbed_sewer' => 'Angel Ramos',
                'flatbed_thread' => 'TC-004 white',
                'pipping_thread' => 'Metallic gold',
                'sewer_notes' => 'Double stitch on the XL pieces.',
            ],
        ])->assertSessionHasNoErrors();

        $jo = $order->fresh()->jobOrder;

        $this->assertSame('Marites Bautista', $jo->neckbond_sewer);
        $this->assertSame('TC-220 navy', $jo->neckbond_thread);
        $this->assertSame('Angel Ramos', $jo->flatbed_sewer);
        $this->assertSame('Metallic gold', $jo->pipping_thread);
        $this->assertSame('Double stitch on the XL pieces.', $jo->sewer_notes);
    }

    public function test_a_box_left_alone_keeps_what_the_last_shift_wrote(): void
    {
        [$sewer, $order] = $this->orderAtSewing();
        $order->jobOrder->update(['neckbond_sewer' => 'Jhun Delos Reyes']);

        // Second shift fills a different seam and leaves the first one blank.
        $order->tasks()->update(['status' => 'ready']);
        $session = $this->runningOn($sewer, 'sewing_1', $order);

        $this->actingAs($sewer)->post("/station-sessions/{$session->id}/end", [
            'end_reason' => 'done',
            'sheet' => ['neckbond_sewer' => '', 'flatbed_sewer' => 'Angel Ramos'],
        ]);

        $jo = $order->fresh()->jobOrder;

        $this->assertSame('Jhun Delos Reyes', $jo->neckbond_sewer,
            'an empty box must not wipe what an earlier shift recorded');
        $this->assertSame('Angel Ramos', $jo->flatbed_sewer);
    }

    public function test_the_checker_writes_the_qc_note(): void
    {
        $sales = User::factory()->create(['job_role' => User::ROLE_SALES, 'is_active' => true]);
        $checker = User::factory()->create(['job_role' => 'Quality control', 'is_active' => true]);

        $order = ProductionOrder::create([
            'order_number' => 'IC2026-0'.random_int(1000, 9999),
            'customer_name' => 'Check Co', 'product_type' => 'round_neck', 'quantity' => 10,
            'due_date' => now()->addWeek(), 'created_by' => $sales->id, 'status' => 'active',
        ]);
        $order->jobOrder()->create(['status' => 'draft', 'created_by' => $sales->id]);
        Task::create([
            'production_order_id' => $order->id, 'department' => 'Quality control',
            'sequence' => 14, 'stage' => 7, 'status' => 'ready',
        ]);

        $session = $this->runningOn($checker, 'qc_1', $order->fresh());

        $this->actingAs($checker)->post("/station-sessions/{$session->id}/end", [
            'end_reason' => 'done',
            'sheet' => ['qc_notes' => 'Two pieces returned for a loose hem; rest passed.'],
        ])->assertSessionHasNoErrors();

        $this->assertSame(
            'Two pieces returned for a loose hem; rest passed.',
            $order->fresh()->jobOrder->qc_notes
        );
    }

    public function test_what_the_checker_wrote_is_printed_on_the_sheet(): void
    {
        [$sewer, $order] = $this->orderAtSewing();
        // The officer who took the order — anyone else is not allowed to open it.
        $sales = User::find($order->created_by);

        $order->jobOrder->update([
            'qc_notes' => 'Two pieces returned for a loose hem.',
            'flatbed_sewer' => 'Angel Ramos',
        ]);

        $this->actingAs($sales)
            ->get("/orders/{$order->id}/job-order")
            ->assertOk()
            ->assertSee('Two pieces returned for a loose hem.', false)
            ->assertSee('ANGEL RAMOS', false);
    }

    public function test_a_station_with_nothing_to_record_is_asked_for_nothing(): void
    {
        // A printer has no part of the sheet to fill — its operator name is
        // stamped automatically, and that is the whole of its contribution.
        $this->assertSame([], \App\Http\Controllers\StationController::sheetFieldsFor('printer_1'));
        $this->assertSame(
            JobOrder::SEWING_STATION_FIELDS,
            \App\Http\Controllers\StationController::sheetFieldsFor('sewing_2')
        );
        $this->assertSame(
            JobOrder::QC_STATION_FIELDS,
            \App\Http\Controllers\StationController::sheetFieldsFor('qc_3')
        );
    }

    public function test_the_office_form_no_longer_asks_for_what_the_floor_records(): void
    {
        [$sewer, $order] = $this->orderAtSewing();
        $sales = User::find($order->created_by);

        $form = $this->actingAs($sales)->get("/job-orders/{$order->id}/edit")->assertOk();

        foreach (JobOrder::SEWING_STATION_FIELDS as $field) {
            $form->assertDontSee('name="'.$field.'"', false);
        }

        // The spec it DOES own is still there.
        $form->assertSee('name="neck"', false)->assertSee('name="neck_size"', false);
    }

    public function test_sewers_and_threads_typed_at_the_station_are_suggested_next_time(): void
    {
        [$sewer, $order] = $this->orderAtSewing();
        $session = $this->runningOn($sewer, 'sewing_1', $order);

        $this->actingAs($sewer)->post("/station-sessions/{$session->id}/end", [
            'end_reason' => 'done',
            'sheet' => ['neckbond_sewer' => 'Marites Bautista', 'pipping_thread' => 'Metallic gold'],
        ]);

        $suggest = JobOrder::fieldSuggestions();

        // One shared pool, so the next job offers them on any seam.
        $this->assertContains('Marites Bautista', $suggest['sewer']);
        $this->assertContains('Metallic gold', $suggest['thread']);
    }
}
