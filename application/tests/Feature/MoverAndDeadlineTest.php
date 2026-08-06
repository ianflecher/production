<?php

namespace Tests\Feature;

use App\Models\ProductionOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Two things the floor asked for:
 *
 *  - The mover walks around chasing progress, so she has to be able to READ
 *    every job order — she was previously locked out of them entirely.
 *  - A job due today that hasn't shipped, or one already past its date, has to
 *    shout, and say where it is stuck and what comes next.
 */
class MoverAndDeadlineTest extends TestCase
{
    use RefreshDatabase;

    private function mover(): User
    {
        return User::factory()->create(['job_role' => 'Mover', 'is_active' => true]);
    }

    private static int $seq = 0;

    private function order(array $attributes = []): ProductionOrder
    {
        $sales = User::factory()->create(['job_role' => User::ROLE_SALES, 'is_active' => true]);

        $order = ProductionOrder::create(array_merge([
            'order_number' => 'IC2026-066'.str_pad((string) ++self::$seq, 2, '0', STR_PAD_LEFT),
            'customer_name' => 'Late Co',
            'product_type' => 'round_neck',
            'quantity' => 20,
            'due_date' => now()->addWeeks(2),
            'status' => 'active',
            'created_by' => $sales->id,
        ], $attributes));

        $order->jobOrder()->create(['status' => 'draft', 'created_by' => $sales->id]);

        return $order->fresh();
    }

    /** Put a released step on the order so there's something to be "stuck at". */
    private function stepAt(ProductionOrder $order, string $department, int $stage, string $status = 'ready'): void
    {
        $order->tasks()->create([
            'sequence' => ($order->tasks()->max('sequence') ?? 0) + 1,
            'stage' => $stage,
            'department' => $department,
            'status' => $status,
            'approver_role' => 'leader',
        ]);
    }

    // ---- The mover -------------------------------------------------------

    public function test_the_mover_is_her_own_role_not_a_plain_agent(): void
    {
        $this->assertSame(User::ROLE_MOVER, $this->mover()->role);
        $this->assertTrue($this->mover()->isMover());
        $this->assertSame('Mover', $this->mover()->roleLabel());
    }

    public function test_the_spelling_of_the_job_role_does_not_matter(): void
    {
        foreach (['Mover', 'mover', ' MOVER '] as $spelling) {
            $person = User::factory()->create(['job_role' => $spelling, 'is_active' => true]);
            $this->assertTrue($person->isMover(), "'$spelling' should be a mover");
        }
    }

    public function test_the_mover_can_read_the_job_orders(): void
    {
        $order = $this->order();

        foreach ([
            '/orders',
            "/orders/{$order->id}",
            "/orders/{$order->id}/job-order",
            '/calendar',
        ] as $url) {
            $this->actingAs($this->mover())->get($url)->assertOk("the mover should be able to open $url");
        }
    }

    public function test_the_mover_sees_every_order_not_just_one_officers(): void
    {
        $this->order(['order_number' => 'IC2026-06601']);
        $this->order(['order_number' => 'IC2026-06602']);

        $this->actingAs($this->mover())->get('/orders')
            ->assertSee('IC2026-06601')
            ->assertSee('IC2026-06602');
    }

    public function test_the_mover_cannot_change_anything(): void
    {
        $order = $this->order();
        $mover = $this->mover();

        // Reading is the whole job — none of these belong to her.
        $this->actingAs($mover)->get('/orders/create')->assertForbidden();
        $this->actingAs($mover)->get("/orders/{$order->id}/edit")->assertForbidden();
        $this->actingAs($mover)->post("/orders/{$order->id}/status", ['action' => 'hold'])->assertForbidden();
        $this->actingAs($mover)->get('/approvals')->assertForbidden();
        $this->actingAs($mover)->get('/users')->assertForbidden();
        $this->actingAs($mover)->get('/books')->assertForbidden();
    }

    public function test_the_mover_has_no_station_of_her_own(): void
    {
        $mover = $this->mover();

        // She closes no step, so she is not on the station board at all.
        $this->assertSame([], \App\Services\Stations::forUser($mover));
        $this->assertFalse($mover->canUseStations());
        $this->assertArrayNotHasKey('mover', \App\Services\Stations::all());
        $this->actingAs($mover)->get('/stations')->assertForbidden();
    }

    public function test_the_sample_step_no_longer_waits_on_a_mover(): void
    {
        // It used to sit at the mover's station until someone closed it. Nobody
        // "works" carrying a shirt across the room, so it now goes straight to
        // the officer's Sample Review.
        $this->assertArrayNotHasKey(
            'Produce sample for client',
            ProductionOrder::DEPARTMENT_ROLES
        );
    }

    public function test_the_first_sample_lands_on_the_officers_desk_by_itself(): void
    {
        $order = $this->order();
        $order->buildPipeline([], 'manual');

        $sample = $order->tasks()->where('department', 'Produce sample for client')->firstOrFail();

        $this->assertTrue((bool) $sample->auto_submit, 'the sample should not wait at a station');
        $this->assertSame('sales', $sample->approver_role);

        // When its stage opens it goes to FOR CHECKING with nobody to chase.
        $order->unlockStage($sample->stage);

        $this->assertSame('for_checking', $sample->fresh()->status);
    }

    // ---- The mover in conversations ---------------------------------------

    public function test_the_mover_is_in_every_job_conversation(): void
    {
        $mover = $this->mover();
        $order = $this->order(['order_number' => 'IC2026-06620']);

        // She holds no task on this order and didn't create it, yet the thread
        // is hers to read and she can be mentioned by name.
        $this->actingAs($mover)->get("/messages/{$order->id}")
            ->assertOk()
            ->assertSee($mover->name, false);

        $this->actingAs($mover)->get('/messages')
            ->assertOk()
            ->assertSee('IC2026-06620', false);
    }

    public function test_mentioning_the_mover_reaches_her(): void
    {
        $mover = $this->mover();
        $order = $this->order();
        $officer = User::find($order->created_by);

        $this->actingAs($officer)->post("/messages/{$order->id}", [
            'body' => "@{$mover->name} why is this one taking so long?",
        ])->assertRedirect();

        $this->assertDatabaseHas('messages', ['production_order_id' => $order->id]);
        $this->assertGreaterThan(
            0,
            \App\Models\AppNotification::where('user_id', $mover->id)->count(),
            'the mover should be told when she is mentioned'
        );
    }

    // ---- Running late ----------------------------------------------------

    public function test_a_job_due_later_says_nothing(): void
    {
        $order = $this->order(['due_date' => now()->addWeeks(2)]);

        $this->assertNull($order->delayState());
        $this->assertNull($order->delayLabel());
    }

    public function test_a_job_due_today_and_still_running_may_be_delayed(): void
    {
        $order = $this->order(['due_date' => now()]);

        $this->assertSame('at_risk', $order->delayState());
        $this->assertSame('PROJECT MAY BE DELAYED', $order->delayLabel());
    }

    public function test_a_job_past_its_date_is_delayed(): void
    {
        $order = $this->order(['due_date' => now()->subDays(3)]);

        $this->assertSame('delayed', $order->delayState());
        $this->assertSame('PROJECT DELAYED', $order->delayLabel());
        $this->assertSame(3, $order->daysLate());
    }

    public function test_a_finished_job_is_never_late(): void
    {
        $order = $this->order(['due_date' => now()->subWeek(), 'status' => 'complete']);

        $this->assertNull($order->delayState(), 'a delivered job cannot be late');
    }

    public function test_a_cancelled_or_held_job_is_not_chased(): void
    {
        foreach (['cancelled', 'on_hold'] as $status) {
            $order = $this->order(['due_date' => now()->subWeek(), 'status' => $status]);

            $this->assertNull($order->delayState(), "a $status job should not be chased");
        }
    }

    public function test_the_alert_names_where_the_job_is_and_what_is_next(): void
    {
        $order = $this->order(['due_date' => now()->subDays(2)]);
        $this->stepAt($order, 'Printer', 3, 'in_progress');
        $this->stepAt($order, 'Sewing', 7);
        $this->stepAt($order, 'Quality control', 8);

        $order = $order->fresh();

        $this->assertSame('Printer', $order->currentStepLabel());
        $this->assertSame('Sewing', $order->nextStepLabel());
    }

    public function test_the_next_step_skips_work_running_alongside_the_current_one(): void
    {
        $order = $this->order(['due_date' => now()]);
        // Printer and Sticker run together at stage 3; Cutting is what follows.
        $this->stepAt($order, 'Printer', 3, 'in_progress');
        $this->stepAt($order, 'Sticker', 3);
        $this->stepAt($order, 'Manual cutting', 5);

        $order = $order->fresh();

        $this->assertSame('Printer', $order->currentStepLabel());
        $this->assertSame(
            'Manual cutting',
            $order->nextStepLabel(),
            'a step sharing the stage is running alongside, not next'
        );
    }

    public function test_a_job_not_yet_started_points_at_its_first_step(): void
    {
        $order = $this->order(['due_date' => now()]);
        $this->stepAt($order, 'Layout', 1, 'todo');
        $this->stepAt($order, 'Final mockup', 2, 'todo');

        $order = $order->fresh();

        $this->assertSame('Not started', $order->currentStepLabel());
        $this->assertSame('Layout', $order->nextStepLabel(), 'it should name what happens first');
    }

    public function test_the_last_step_has_nothing_after_it(): void
    {
        $order = $this->order(['due_date' => now()]);
        $this->stepAt($order, 'Release to client', 16, 'in_progress');

        $this->assertNull($order->fresh()->nextStepLabel());
    }

    public function test_the_red_alert_shows_on_the_order_page(): void
    {
        $order = $this->order(['due_date' => now()->subDays(4)]);
        $this->stepAt($order, 'Sewing', 7, 'in_progress');
        $this->stepAt($order, 'Quality control', 8);

        $this->actingAs($this->mover())->get("/orders/{$order->id}")
            ->assertOk()
            ->assertSee('PROJECT DELAYED', false)
            ->assertSee('delay-alert is-late', false)
            ->assertSee('Sewing', false)
            ->assertSee('Quality control', false);
    }

    public function test_the_amber_warning_shows_for_a_job_due_today(): void
    {
        $order = $this->order(['due_date' => now()]);
        $this->stepAt($order, 'Pairing', 6, 'in_progress');

        $this->actingAs($this->mover())->get("/orders/{$order->id}")
            ->assertOk()
            ->assertSee('PROJECT MAY BE DELAYED', false)
            ->assertSee('delay-alert is-at-risk', false);
    }

    public function test_a_healthy_job_shows_no_alert(): void
    {
        $order = $this->order(['due_date' => now()->addWeeks(3)]);
        $this->stepAt($order, 'Sewing', 7, 'in_progress');

        $this->actingAs($this->mover())->get("/orders/{$order->id}")
            ->assertOk()
            ->assertDontSee('PROJECT DELAYED', false)
            ->assertDontSee('PROJECT MAY BE DELAYED', false);
    }

    /** The floor can't reach /orders, so the board has to carry the warning. */
    public function test_the_station_board_flags_a_late_job(): void
    {
        $pairing = User::factory()->create(['job_role' => 'Pairing', 'is_active' => true]);

        $late = $this->order(['order_number' => 'IC2026-06630', 'due_date' => now()->subDays(4)]);
        $this->stepAt($late, 'Pairing', 6, 'ready');

        $onTime = $this->order(['order_number' => 'IC2026-06631', 'due_date' => now()->addMonth()]);
        $this->stepAt($onTime, 'Pairing', 6, 'ready');

        $response = $this->actingAs($pairing)->get('/stations');

        $response->assertOk()
            ->assertSee('IC2026-06630', false)
            ->assertSee('IC2026-06631', false)
            ->assertSee('DELAYED', false)
            ->assertSee('LATE', false);

        // The late one is listed first, so whoever takes the station works it next.
        $body = $response->getContent();
        $this->assertLessThan(
            strpos($body, 'IC2026-06631'),
            strpos($body, 'IC2026-06630'),
            'the late job should be at the top of the station queue'
        );
    }

    public function test_a_station_with_nothing_late_stays_quiet(): void
    {
        $pairing = User::factory()->create(['job_role' => 'Pairing', 'is_active' => true]);

        $order = $this->order(['due_date' => now()->addMonth()]);
        $this->stepAt($order, 'Pairing', 6, 'ready');

        $this->actingAs($pairing)->get('/stations')
            ->assertOk()
            ->assertDontSee('DELAYED', false);
    }

    public function test_the_work_sheet_carries_the_warning(): void
    {
        $pairing = User::factory()->create(['job_role' => 'Pairing', 'is_active' => true]);

        $order = $this->order(['due_date' => now()->subDays(3)]);
        $this->stepAt($order, 'Pairing', 6, 'in_progress');
        $this->stepAt($order, 'Sewing', 7);

        $this->actingAs($pairing)->get("/orders/{$order->id}/package?for=production")
            ->assertOk()
            ->assertSee('PROJECT DELAYED', false)
            ->assertSee('Pairing', false);
    }

    public function test_the_orders_list_flags_the_late_ones(): void
    {
        $late = $this->order(['order_number' => 'IC2026-06610', 'due_date' => now()->subDays(5)]);
        $this->stepAt($late, 'Printer', 3, 'in_progress');
        $this->order(['order_number' => 'IC2026-06611', 'due_date' => now()->addMonth()]);

        $this->actingAs($this->mover())->get('/orders')
            ->assertOk()
            ->assertSee('PROJECT DELAYED', false)
            ->assertSee('delay-chip', false);
    }
}
