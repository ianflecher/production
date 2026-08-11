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
    private function runningOn(User $who, string $station, ProductionOrder $order, string $operator = 'Marites Bautista'): StationSession
    {
        $this->actingAs($who)->post('/stations/start', [
            'station' => $station,
            'operator_name' => $operator,
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

        // The spec it DOES own is still there: what kind of collar, not what
        // it measured out at.
        $form->assertSee('name="neck"', false)
            ->assertSee('name="cuff_arm_sleeves"', false)
            ->assertSee('name="bottom_hem"', false);
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

    public function test_finishing_closes_the_step_and_credits_the_names_off_the_sheet(): void
    {
        [$sewer, $order] = $this->orderAtSewing();
        $session = $this->runningOn($sewer, 'sewing_1', $order);

        $this->actingAs($sewer)->post("/station-sessions/{$session->id}/end", [
            'end_reason' => 'done',
            'sheet' => [
                'neckbond_sewer' => 'Marites Bautista',
                'flatbed_sewer' => 'Angel Ramos',
            ],
        ])->assertSessionHasNoErrors();

        $task = $order->fresh()->tasks()->where('department', 'Sewing')->first();

        $this->assertSame('complete', $task->status);
        // Nobody typed a name on the way in — these come off the sheet.
        $this->assertStringContainsString('Marites Bautista', (string) $task->operator_name);
        $this->assertStringContainsString('Angel Ramos', (string) $task->operator_name);
    }

    public function test_clicking_a_job_order_starts_the_clock_and_opens_its_sheet(): void
    {
        [$sewer, $order] = $this->orderAtSewing();

        $this->actingAs($sewer)
            ->post("/stations/sewing_1/work/{$order->id}")
            ->assertRedirect();

        $session = StationSession::where('station', 'sewing_1')->whereNull('ended_at')->first();

        $this->assertNotNull($session, 'clicking the job order should start a run');
        $this->assertSame($order->id, $session->production_order_id);
        $this->assertNotNull($session->started_at, 'that click is what starts the clock');
    }

    public function test_a_finished_run_explains_itself_instead_of_answering_forbidden(): void
    {
        [$sewer, $order] = $this->orderAtSewing();
        $session = $this->runningOn($sewer, 'sewing_1', $order);

        $this->actingAs($sewer)->post("/station-sessions/{$session->id}/end", ['end_reason' => 'done']);

        // A board left open in another tab still links here. Telling somebody
        // who did nothing wrong that they are Forbidden is untrue and unhelpful.
        $this->actingAs($sewer)
            ->get(route('stations.finish', $session))
            ->assertRedirect(route('stations.index'))
            ->assertSessionHas('success');
    }

    public function test_finish_opens_the_sheet_rather_than_closing_the_step(): void
    {
        [$sewer, $order] = $this->orderAtSewing();
        $session = $this->runningOn($sewer, 'sewing_1', $order);

        $this->actingAs($sewer)
            ->get("/station-sessions/{$session->id}/finish")
            ->assertOk()
            // The boxes are IN the sheet, not in a second list of the same
            // questions underneath it.
            ->assertSee('class="fill-in" name="sheet[neckbond_sewer]"', false)
            ->assertSee('Neckbond Shoulder', false);

        // Opening the page must not have closed anything on its own.
        $this->assertNotSame('complete', $order->fresh()->tasks()->where('department', 'Sewing')->value('status'));
    }

    public function test_the_sheet_stays_read_only_everywhere_else(): void
    {
        [$sewer, $order] = $this->orderAtSewing();
        $sales = User::find($order->created_by);

        // The same partial renders the sheet on the order page. An account
        // officer works no station, so it stays read-only for them.
        $this->actingAs($sales)
            ->get("/orders/{$order->id}/job-order")
            ->assertOk()
            ->assertDontSee('name="sheet[', false);

        // …and the package document is read-only for everybody.
        $this->actingAs($sewer)
            ->get("/orders/{$order->id}/package")
            ->assertOk()
            ->assertDontSee('name="sheet[', false);
    }

    /**
     * The finish page needs a RUNNING session. Sending the sewer "back" to it
     * after their run has ended answered 403 to somebody who had just done
     * everything right — so both answers land on the station board instead.
     */
    public function test_finishing_lands_on_the_board_and_not_on_a_forbidden_page(): void
    {
        foreach ([1, 2] as $ignored) {
            [$sewer, $order] = $this->orderAtSewing();
            $session = $this->runningOn($sewer, 'sewing_1', $order);

            $this->actingAs($sewer)
                ->from(route('stations.finish', $session))
                ->post("/station-sessions/{$session->id}/end", [
                    'end_reason' => 'done',
                    'sheet' => ['neckbond_sewer' => 'Marites Bautista'],
                ])
                ->assertRedirect(route('stations.index'));

            // And the page they came from no longer holds a run, which is why
            // they must not be sent back to it — it bounces to the board.
            $this->actingAs($sewer)
                ->get(route('stations.finish', $session))
                ->assertRedirect(route('stations.index'));
        }
    }
}
