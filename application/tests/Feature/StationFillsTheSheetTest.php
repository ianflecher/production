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

    public function test_the_sewer_writes_who_did_what_when_they_finish(): void
    {
        [$sewer, $order] = $this->orderAtSewing();
        $session = $this->runningOn($sewer, 'sewing_1', $order);

        $this->actingAs($sewer)->post("/station-sessions/{$session->id}/end", [
            'end_reason' => 'done',
            'sheet' => [
                'sewing_log' => [
                    ['work' => 'Closed the sides', 'name' => 'Marites Bautista'],
                    ['work' => 'Attached the sleeves', 'name' => 'Angel Ramos'],
                    ['work' => '', 'name' => ''],
                    ['work' => '', 'name' => ''],
                    ['work' => '', 'name' => ''],
                ],
            ],
        ])->assertSessionHasNoErrors();

        $log = $order->fresh()->jobOrder->sewing_log;

        // The slots nobody used are not kept as three empty rows.
        $this->assertCount(2, $log);
        $this->assertSame('Closed the sides', $log[0]['work']);
        $this->assertSame('Marites Bautista', $log[0]['name']);
        $this->assertSame('Angel Ramos', $log[1]['name']);
    }

    public function test_five_slots_is_room_not_a_quota(): void
    {
        [$sewer, $order] = $this->orderAtSewing();
        $session = $this->runningOn($sewer, 'sewing_1', $order);

        // One person did the whole thing. That is a normal job.
        $this->actingAs($sewer)->post("/station-sessions/{$session->id}/end", [
            'end_reason' => 'done',
            'sheet' => ['sewing_log' => [['work' => 'All of it', 'name' => 'Jhun Delos Reyes']]],
        ])->assertSessionHasNoErrors();

        $this->assertCount(1, $order->fresh()->jobOrder->sewing_log);
    }

    public function test_the_step_carries_a_note_about_what_happened(): void
    {
        [$sewer, $order] = $this->orderAtSewing();
        $session = $this->runningOn($sewer, 'sewing_1', $order);

        // Not the spec, and not why it came back — what happened while it was
        // being made. That used to go in a group chat, or nowhere.
        $this->actingAs($sewer)->post("/station-sessions/{$session->id}/end", [
            'end_reason' => 'done',
            'task_note' => 'Machine 2 was down till noon, ran the batch on 3.',
            'sheet' => ['sewing_log' => [['work' => 'All of it', 'name' => 'Marites Bautista']]],
        ])->assertSessionHasNoErrors();

        $task = $order->fresh()->tasks()->where('department', 'Sewing')->first();

        $this->assertSame('Machine 2 was down till noon, ran the batch on 3.', $task->note);
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

    public function test_what_the_floor_wrote_is_printed_on_the_sheet(): void
    {
        [$sewer, $order] = $this->orderAtSewing();

        $order->jobOrder->update([
            'sewing_log' => [['work' => 'Attached the sleeves', 'name' => 'Angel Ramos']],
        ]);

        // The production record was deliberately removed from the Tech Pack.
        // It remains on the floor's separate correction sheet, where the
        // people who actually did the work can read and correct it.
        $this->actingAs($sewer)
            ->get(route('orders.sheet', $order))
            ->assertOk()
            ->assertSee('Attached the sleeves', false)
            ->assertSee('Angel Ramos', false)
            ->assertDontSee('Quality Check', false);
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

    public function test_the_artist_form_never_asks_for_what_the_floor_records(): void
    {
        // The pack only opens once the client has approved the mockup, so the
        // fixture has to get that far before the page can be read.
        [$sewer, $order] = $this->orderAtSewing();
        $artist = User::factory()->create(['job_role' => User::JOB_ARTIST, 'is_active' => true]);

        Task::create([
            'production_order_id' => $order->id, 'department' => 'Final mockup',
            'sequence' => 2, 'stage' => 2, 'status' => 'complete', 'approved_at' => now(),
            'assigned_to' => $artist->id,
        ]);
        $pack = Task::create([
            'production_order_id' => $order->id, 'department' => 'Tech pack',
            'sequence' => 3, 'stage' => 2, 'status' => 'in_progress',
            'approver_role' => 'sales', 'assigned_to' => $artist->id,
        ]);
        $order->jobOrder->update(['status' => 'sent_to_artist']);

        $form = $this->actingAs($artist)->get(route('tasks.job-order', $pack))->assertOk();

        foreach (JobOrder::SEWING_STATION_FIELDS as $field) {
            $form->assertDontSee('name="'.$field.'"', false);
        }

        // The spec it DOES own is still there: what kind of collar, not what
        // it measured out at.
        $form->assertSee('name="neck"', false)
            ->assertSee('name="cuff_arm_sleeves"', false)
            ->assertSee('name="bottom_hem"', false);
    }

    public function test_sewers_typed_at_the_station_are_suggested_next_time(): void
    {
        [$sewer, $order] = $this->orderAtSewing();
        $session = $this->runningOn($sewer, 'sewing_1', $order);

        $this->actingAs($sewer)->post("/station-sessions/{$session->id}/end", [
            'end_reason' => 'done',
            'sheet' => ['sewing_log' => [['work' => 'Closed the sides', 'name' => 'Marites Bautista']]],
        ]);

        // One shared pool: the next job offers the people who did the last one,
        // whether their name went into the log or an older seam column.
        $this->assertContains('Marites Bautista', JobOrder::stationSuggestions()['sewer']);
    }

    public function test_finishing_closes_the_step_and_credits_the_names_off_the_sheet(): void
    {
        [$sewer, $order] = $this->orderAtSewing();
        $session = $this->runningOn($sewer, 'sewing_1', $order);

        $this->actingAs($sewer)->post("/station-sessions/{$session->id}/end", [
            'end_reason' => 'done',
            'sheet' => [
                'sewing_log' => [
                    ['work' => 'Closed the sides', 'name' => 'Marites Bautista'],
                    ['work' => 'Attached the sleeves', 'name' => 'Angel Ramos'],
                ],
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
            // Five slots: what was done, and who did it.
            ->assertSee('name="sheet[sewing_log][0][name]"', false)
            ->assertSee('name="sheet[sewing_log][4][work]"', false)
            ->assertSee('Who sewed this', false);

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

    public function test_back_saves_what_was_typed_instead_of_throwing_it_away(): void
    {
        [$sewer, $order] = $this->orderAtSewing();
        $session = $this->runningOn($sewer, 'sewing_1', $order);

        $this->actingAs($sewer)->post("/station-sessions/{$session->id}/end", [
            'end_reason' => 'done',
            'keep_working' => 1,
            'sheet' => ['sewing_log' => [['work' => 'Closed the sides', 'name' => 'Marites Bautista']]],
        ])->assertRedirect(route('stations.index'));

        $this->assertSame('Marites Bautista', $order->fresh()->jobOrder->sewing_log[0]['name'],
            'stepping away must not throw away a shift of typing');
        $this->assertTrue(StationSession::find($session->id)->isRunning(),
            'the job is still on the machine, so the clock keeps running');
    }

    public function test_a_sewer_is_never_asked_to_sign_the_quality_check(): void
    {
        $sewer = User::factory()->make(['job_role' => 'Sewing']);
        $checker = User::factory()->make(['job_role' => 'Quality control']);

        $sewerFields = \App\Http\Controllers\StationController::sheetFieldsForUser($sewer);
        $checkerFields = \App\Http\Controllers\StationController::sheetFieldsForUser($checker);

        $this->assertEmpty(array_intersect($sewerFields, JobOrder::QC_STATION_FIELDS),
            "the QC line is not for the sewer to write");
        $this->assertEmpty(array_intersect($checkerFields, JobOrder::SEWING_STATION_FIELDS),
            "the seams are not for the checker to write");
        $this->assertNotEmpty($sewerFields);
        $this->assertNotEmpty($checkerFields);
    }

    public function test_the_sheet_stays_correctable_until_the_whole_order_is_finished(): void
    {
        [$sewer, $order] = $this->orderAtSewing();
        $order->jobOrder->update(['sewing_log' => [['work' => 'Closed the sides', 'name' => 'Marites']]]);

        // Still running: the floor can open it and put a wrong entry right.
        $this->actingAs($sewer)->get(route('orders.sheet', $order))->assertOk();

        $this->actingAs($sewer)->post(route('orders.sheet.update', $order), [
            'sheet' => ['sewing_log' => [['work' => 'Closed the sides', 'name' => 'Marites Bautista']]],
        ])->assertSessionHasNoErrors();

        $this->assertSame('Marites Bautista', $order->fresh()->jobOrder->sewing_log[0]['name']);

        // Finished: the sheet is a record of what was made, and records do not
        // change.
        $order->update(['status' => 'complete']);

        $this->actingAs($sewer)->get(route('orders.sheet', $order))
            ->assertRedirect(route('stations.index'));
        $this->actingAs($sewer)->post(route('orders.sheet.update', $order), [
            'sheet' => ['sewing_log' => [['work' => 'Closed the sides', 'name' => 'Too Late']]],
        ])->assertForbidden();

        $this->assertSame('Marites Bautista', $order->fresh()->jobOrder->sewing_log[0]['name']);
    }
}
