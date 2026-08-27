<?php

namespace Tests\Feature;

use App\Models\ProductionOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The month grid is the whole shop's capacity, so everybody sees every job —
 * hiding half of it makes the picture a lie. Opening one is a different
 * question: leaders, supervisors and the admin can open any, everyone else
 * only their own.
 *
 * The upcoming-deadline list is not capacity, it is a to-do — so that one is
 * narrowed to the person's own jobs. Nobody needs reminding of a deadline they
 * cannot act on.
 */
class CalendarAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        /*
         * The grid draws ONE month, and these orders are due five days out. Run
         * on the 27th, five days out is next month, so the job the officer did
         * not create — the one that only reaches the page through the grid —
         * was not on the page to find. The test passed all month and failed in
         * the last few days of it.
         *
         * Held to the 10th so "five days from now" is always the same month.
         */
        $this->travelTo(now()->startOfMonth()->addDays(9)->setTime(9, 0));
    }

    private function officer(string $name = 'Rey'): User
    {
        return User::factory()->create(['job_role' => User::ROLE_SALES, 'name' => $name, 'is_active' => true]);
    }

    private function orderFor(User $officer, string $number, ?\Closure $tweak = null): ProductionOrder
    {
        $order = ProductionOrder::create([
            'order_number' => $number,
            'customer_name' => 'Cal Co',
            'product_type' => 'round_neck',
            'quantity' => 20,
            'due_date' => now()->addDays(5),
            'status' => 'active',
            'created_by' => $officer->id,
        ]);

        $tweak && $tweak($order);

        return $order->fresh();
    }

    // ---- The month grid ----------------------------------------------------

    public function test_an_officer_sees_every_job_on_the_grid(): void
    {
        $mine = $this->orderFor($this->officer(), 'IC2026-04001');
        $theirs = $this->orderFor($this->officer('Nasser'), 'IC2026-04002');

        $me = User::find($mine->created_by);

        // Capacity is company-wide; a calendar showing only your own work
        // cannot tell you whether a date is full.
        $this->actingAs($me)->get('/calendar')
            ->assertOk()
            ->assertSee('IC2026-04001', false)
            ->assertSee('IC2026-04002', false);
    }

    public function test_but_can_only_open_their_own(): void
    {
        $mine = $this->orderFor($this->officer(), 'IC2026-04001');
        $theirs = $this->orderFor($this->officer('Nasser'), 'IC2026-04002');

        $me = User::find($mine->created_by);
        $page = $this->actingAs($me)->get('/calendar')->assertOk();

        $page->assertSee(route('orders.show', $mine), false);
        $page->assertDontSee(route('orders.show', $theirs), false);
        $page->assertDontSee(route('orders.job-order', $theirs), false);
    }

    public function test_someone_elses_job_is_shown_but_not_as_a_link(): void
    {
        $this->orderFor($this->officer(), 'IC2026-04001');
        $theirs = $this->orderFor($this->officer('Nasser'), 'IC2026-04002');

        $me = User::factory()->create(['job_role' => User::ROLE_SALES, 'is_active' => true]);

        $this->actingAs($me)->get('/calendar')
            ->assertOk()
            ->assertSee('IC2026-04002', false)
            ->assertSee('is-locked', false);
    }

    public function test_a_leader_can_open_anybodys(): void
    {
        $a = $this->orderFor($this->officer(), 'IC2026-04001');
        $b = $this->orderFor($this->officer('Nasser'), 'IC2026-04002');

        $leader = User::factory()->create(['job_role' => User::ROLE_LEADER, 'is_active' => true]);

        $this->actingAs($leader)->get('/calendar')
            ->assertOk()
            ->assertSee(route('orders.show', $a), false)
            ->assertSee(route('orders.show', $b), false);
    }

    public function test_a_supervisor_counts_as_a_leader(): void
    {
        $order = $this->orderFor($this->officer(), 'IC2026-04001');
        $supervisor = User::factory()->create(['job_role' => 'Supervisor', 'is_active' => true]);

        $this->assertTrue($order->openableBy($supervisor));
    }

    public function test_the_mover_opens_a_job_once_it_is_on_the_floor(): void
    {
        $officer = $this->officer();
        $mover = User::factory()->create(['job_role' => 'Mover', 'is_active' => true]);

        $waiting = $this->orderFor($officer, 'IC2026-04003');
        $onFloor = $this->orderFor($officer, 'IC2026-04004', function ($o) {
            $o->tasks()->create([
                'sequence' => 1, 'stage' => 3, 'department' => 'Printer',
                'status' => 'in_progress', 'approver_role' => 'leader',
                'released_at' => now()->subHour(),
            ]);
        });

        $this->assertFalse($waiting->openableBy($mover));
        $this->assertTrue($onFloor->fresh()->openableBy($mover));
    }

    // ---- The upcoming deadlines --------------------------------------------

    public function test_the_deadline_list_is_only_your_own(): void
    {
        $mine = $this->orderFor($this->officer(), 'IC2026-04001');
        $theirs = $this->orderFor($this->officer('Nasser'), 'IC2026-04002');

        $me = User::find($mine->created_by);
        $upcoming = $this->actingAs($me)->get('/calendar')->assertOk()->viewData('upcoming');

        $numbers = $upcoming->pluck('order_number')->all();

        $this->assertContains('IC2026-04001', $numbers);
        $this->assertNotContains('IC2026-04002', $numbers, 'a deadline you cannot act on is noise');
    }

    public function test_a_leader_gets_every_deadline(): void
    {
        $this->orderFor($this->officer(), 'IC2026-04001');
        $this->orderFor($this->officer('Nasser'), 'IC2026-04002');

        $leader = User::factory()->create(['job_role' => User::ROLE_LEADER, 'is_active' => true]);
        $upcoming = $this->actingAs($leader)->get('/calendar')->assertOk()->viewData('upcoming');

        $this->assertCount(2, $upcoming);
    }
}
