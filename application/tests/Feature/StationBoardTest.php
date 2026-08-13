<?php

namespace Tests\Feature;

use App\Models\JobOrder;
use App\Models\ProductionOrder;
use App\Models\StationSession;
use App\Models\Task;
use App\Models\User;
use App\Services\Stations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * What the station board actually puts in front of an operator: the jobs that
 * have reached their machine, which step is waiting there, and who is on it.
 */
class StationBoardTest extends TestCase
{
    use RefreshDatabase;

    private function leader(): User
    {
        return User::factory()->create(['job_role' => User::ROLE_LEADER, 'is_active' => true]);
    }

    /** An active order whose given department is released to the floor. */
    private function orderAt(string $department, ?string $printer = null, string $number = 'IC2026-09001'): ProductionOrder
    {
        $order = ProductionOrder::create([
            'order_number' => $number,
            'customer_name' => 'Board Co',
            'product_type' => 'round_neck',
            'quantity' => 20,
            'due_date' => now()->addWeeks(3),
            'status' => 'active',
            'created_by' => $this->leader()->id,
        ]);

        $order->jobOrder()->create(['status' => 'sent_to_artist', 'printer' => $printer]);

        $order->tasks()->create([
            'sequence' => 1,
            'stage' => 3,
            'department' => $department,
            'status' => 'ready',
            'approver_role' => 'leader',
        ]);

        return $order->fresh();
    }

    /** Find one station's entry in the board's grouped output. */
    private function stationEntry($response, string $station): ?array
    {
        foreach ($response->viewData('groups') as $entries) {
            foreach ($entries as $entry) {
                if ($entry['key'] === $station) {
                    return $entry;
                }
            }
        }

        return null;
    }

    public function test_a_released_job_shows_at_the_matching_station(): void
    {
        $order = $this->orderAt('Sewing');

        $response = $this->actingAs($this->leader())->get('/stations');
        $response->assertOk();

        $sewing = $this->stationEntry($response, 'sewing_1');
        $this->assertNotNull($sewing, 'the sewing station should be on the board');
        $this->assertTrue(
            collect($sewing['orders'])->contains(fn ($o) => $o->id === $order->id)
        );
    }

    public function test_a_job_that_has_not_reached_the_floor_does_not_show(): void
    {
        $order = $this->orderAt('Sewing');
        $order->tasks()->update(['status' => 'todo']);

        $response = $this->actingAs($this->leader())->get('/stations');

        $sewing = $this->stationEntry($response, 'sewing_1');
        $this->assertEmpty($sewing['orders'], 'a step still TODO has not reached the station');
    }

    public function test_a_completed_step_leaves_the_station(): void
    {
        $order = $this->orderAt('Sewing');
        $order->tasks()->update(['status' => 'complete']);

        $response = $this->actingAs($this->leader())->get('/stations');

        $this->assertEmpty($this->stationEntry($response, 'sewing_1')['orders']);
    }

    public function test_a_cancelled_order_leaves_the_board(): void
    {
        $order = $this->orderAt('Sewing');
        $order->update(['status' => 'cancelled']);

        $response = $this->actingAs($this->leader())->get('/stations');

        $this->assertEmpty($this->stationEntry($response, 'sewing_1')['orders']);
    }

    public function test_a_printer_only_shows_the_jobs_routed_to_it(): void
    {
        $atexco = $this->orderAt('Printer', 'atexco', 'IC2026-09002');
        $dtf = $this->orderAt('Printer', 'dtf_printer', 'IC2026-09003');

        $response = $this->actingAs($this->leader())->get('/stations');

        $onAtexco = collect($this->stationEntry($response, 'printer_atexco')['orders'])->pluck('id');
        $onDtf = collect($this->stationEntry($response, 'printer_dtf_printer')['orders'])->pluck('id');

        $this->assertTrue($onAtexco->contains($atexco->id));
        $this->assertFalse($onAtexco->contains($dtf->id), 'a DTF job must not appear on the Atexco');
        $this->assertTrue($onDtf->contains($dtf->id));
        $this->assertFalse($onDtf->contains($atexco->id));
    }

    public function test_the_board_names_the_step_waiting_at_the_station(): void
    {
        $this->orderAt('Mass production', 'atexco');

        $response = $this->actingAs($this->leader())->get('/stations');
        $order = collect($this->stationEntry($response, 'printer_atexco')['orders'])->first();

        // The printers run both the first print and the full batch, and the two
        // read very differently to the operator.
        $this->assertSame('Mass production', $order->station_step);
    }

    public function test_the_same_job_carries_its_own_step_at_each_station(): void
    {
        $order = $this->orderAt('Printer', 'atexco');
        $order->tasks()->create([
            'sequence' => 2, 'stage' => 7, 'department' => 'Sewing',
            'status' => 'ready', 'approver_role' => 'leader',
        ]);

        $response = $this->actingAs($this->leader())->get('/stations');

        $atPrinter = collect($this->stationEntry($response, 'printer_atexco')['orders'])->first();
        $atSewing = collect($this->stationEntry($response, 'sewing_1')['orders'])->first();

        $this->assertSame('Printer', $atPrinter->station_step);
        $this->assertSame('Sewing', $atSewing->station_step, 'one station must not overwrite another\'s step');
    }

    /**
     * A job on one of the ten sewing machines has to drop off the other nine,
     * or two people pick it up and only find out at the seam.
     */
    public function test_a_job_on_one_sewing_machine_leaves_the_other_sewing_cards(): void
    {
        $order = $this->orderAt('Sewing');

        StationSession::create([
            'station' => 'sewing_1', 'production_order_id' => $order->id,
            'user_id' => $this->leader()->id, 'operator_name' => 'Jully', 'started_at' => now(),
        ]);

        $response = $this->actingAs($this->leader())->get('/stations');

        $this->assertEmpty($this->stationEntry($response, 'sewing_2')['orders'],
            'another sewing machine must not offer a job already being sewn');
    }

    /**
     * But a job CAN honestly be in two places at once.
     *
     * The stickers for an order print while the shirts themselves wait for the
     * printer. Hiding a running job from every station on the board — rather
     * than only from the ones doing that same work — made the shirts invisible
     * to Atexco: its badge counted the job, the list under it did not show it,
     * and it reappeared only once somebody started the station and opened the
     * dropdown.
     */
    public function test_stickers_printing_do_not_hide_the_shirts_from_the_printer(): void
    {
        $order = $this->orderAt('Printer', 'atexco', 'IC2026-09083');
        $order->tasks()->create([
            'sequence' => 2, 'stage' => 3, 'department' => 'Sticker',
            'status' => 'in_progress', 'approver_role' => 'leader',
        ]);

        // Somebody is running the sticker printer for this same order.
        StationSession::create([
            'station' => 'sticker', 'production_order_id' => $order->id,
            'user_id' => $this->leader()->id, 'operator_name' => 'ian', 'started_at' => now(),
        ]);

        $response = $this->actingAs($this->leader())->get('/stations');
        $atexco = $this->stationEntry($response, 'printer_atexco');

        $this->assertTrue(
            collect($atexco['orders'])->contains(fn ($o) => $o->id === $order->id),
            'Atexco is still waiting for this job — printing its stickers is different work'
        );

        // On the PAGE, not just in the data behind it: the job was dropped
        // while the card was drawn, so everything above this line passed
        // happily while the operator saw an empty list.
        //
        // Anchored to the work-sheet link that only a QUEUE ROW draws. Looking
        // for the order number alone finds it in the start-the-station
        // dropdown, which kept offering the job it had just refused to list —
        // the very thing being fixed, so the loose check passes on the bug.
        $response->assertSee(
            route('orders.package', [$order, 'for' => Stations::scope('printer_atexco')]),
            false
        );
    }

    /** The count on the badge, the list under it and the dropdown are one set. */
    public function test_the_badge_count_matches_the_list_the_operator_can_see(): void
    {
        $order = $this->orderAt('Sewing');
        StationSession::create([
            'station' => 'sewing_1', 'production_order_id' => $order->id,
            'user_id' => $this->leader()->id, 'operator_name' => 'Jully', 'started_at' => now(),
        ]);

        $response = $this->actingAs($this->leader())->get('/stations');
        $card = $this->stationEntry($response, 'sewing_2');

        $this->assertSame(
            collect($card['orders'])->count(),
            $card['queue']->total(),
            'the badge counted jobs the list below it refused to draw'
        );
    }

    public function test_the_board_shows_who_is_running_a_station(): void
    {
        $order = $this->orderAt('Sewing');
        $operator = $this->leader();

        StationSession::create([
            'station' => 'sewing_1',
            'production_order_id' => $order->id,
            'user_id' => $operator->id,
            'operator_name' => 'Jully',
            'started_at' => now(),
        ]);

        $response = $this->actingAs($this->leader())->get('/stations');
        $session = $this->stationEntry($response, 'sewing_1')['session'];

        $this->assertNotNull($session);
        $this->assertSame('Jully', $session->operator_name);
    }

    public function test_a_handed_over_station_shows_the_current_operator(): void
    {
        $order = $this->orderAt('Sewing');
        $operator = $this->leader();

        StationSession::create([
            'station' => 'sewing_1', 'production_order_id' => $order->id,
            'user_id' => $operator->id, 'operator_name' => 'Jully',
            'started_at' => now()->subHours(2), 'ended_at' => now()->subHour(),
        ]);
        StationSession::create([
            'station' => 'sewing_1', 'production_order_id' => $order->id,
            'user_id' => $operator->id, 'operator_name' => 'Maru',
            'started_at' => now()->subHour(),
        ]);

        $response = $this->actingAs($this->leader())->get('/stations');

        $this->assertSame('Maru', $this->stationEntry($response, 'sewing_1')['session']->operator_name);
    }

    public function test_an_idle_station_has_no_session(): void
    {
        $this->orderAt('Sewing');

        $response = $this->actingAs($this->leader())->get('/stations');

        $this->assertNull($this->stationEntry($response, 'sewing_2')['session']);
    }

    /** The board must not get slower as more jobs reach the floor. */
    public function test_the_board_cost_does_not_grow_with_the_number_of_jobs(): void
    {
        $count = function () {
            $queries = 0;
            \DB::listen(function () use (&$queries) {
                $queries++;
            });
            $this->actingAs($this->leader())->get('/stations')->assertOk();

            return $queries;
        };

        $this->orderAt('Sewing', null, 'IC2026-09010');
        $few = $count();

        foreach (range(11, 25) as $n) {
            $this->orderAt('Sewing', null, 'IC2026-090'.$n);
        }
        $many = $count();

        $this->assertLessThanOrEqual(
            $few + 2,
            $many,
            "the board fired $many queries for 16 jobs vs $few for one — it is querying per job again"
        );
    }
}
