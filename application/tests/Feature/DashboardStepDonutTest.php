<?php

namespace Tests\Feature;

use App\Models\ProductionOrder;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The dashboard donut shows where the work IS.
 *
 * It used to have one wedge called "In production" holding every order past
 * the design stage — which on a shop where everything is past design is one
 * colour filling the chart and answering nothing. Eleven at Sewing and two at
 * the printer is a different morning from the reverse, and the chart could not
 * tell you which one you were having.
 *
 * It was also drawn from the five most recent orders, so it was a distribution
 * of five.
 */
class DashboardStepDonutTest extends TestCase
{
    use RefreshDatabase;

    private int $n = 0;

    private function orderAt(?string $department, string $status = 'active'): ProductionOrder
    {
        $sales = User::factory()->create(['job_role' => User::ROLE_SALES, 'is_active' => true]);

        $order = ProductionOrder::create([
            'order_number' => 'IC2026-1'.str_pad((string) ++$this->n, 4, '0', STR_PAD_LEFT),
            'customer_name' => 'Step Co', 'product_type' => 'round_neck', 'quantity' => 10,
            'due_date' => now()->addWeek(), 'created_by' => $sales->id, 'status' => $status,
        ]);

        if ($department !== null) {
            Task::create([
                'production_order_id' => $order->id, 'department' => $department,
                'sequence' => 1, 'stage' => 3, 'status' => 'ready',
            ]);
        }

        return $order;
    }

    /** label => count, as the donut draws it. */
    private function slices(): array
    {
        $leader = User::factory()->create(['job_role' => User::ROLE_LEADER, 'is_active' => true]);

        return collect($this->actingAs($leader)->get('/dashboard')->assertOk()->viewData('stepSlices'))
            ->pluck('value', 'label')->all();
    }

    public function test_each_step_gets_its_own_wedge(): void
    {
        $this->orderAt('Sewing');
        $this->orderAt('Sewing');
        $this->orderAt('Printer');

        $this->assertSame(['Sewing' => 2, 'Printer' => 1], $this->slices());
    }

    public function test_there_is_no_single_in_production_lump(): void
    {
        $this->orderAt('Sewing');
        $this->orderAt('Quality control');

        $this->assertArrayNotHasKey('In production', $this->slices());
    }

    public function test_finished_and_parked_work_keep_their_own_wedges(): void
    {
        $this->orderAt('Sewing');
        $this->orderAt(null, 'complete');
        $this->orderAt('Printer', 'on_hold');

        $slices = $this->slices();

        $this->assertSame(1, $slices['Completed']);
        $this->assertSame(1, $slices['On hold']);
        $this->assertSame(1, $slices['Sewing']);
    }

    public function test_it_counts_every_order_not_just_the_recent_ones(): void
    {
        // The list beneath the chart shows five; the chart must not.
        foreach (range(1, 9) as $i) {
            $this->orderAt('Sewing');
        }

        $this->assertSame(9, $this->slices()['Sewing']);
    }

    public function test_an_order_with_no_open_step_says_so(): void
    {
        $this->orderAt(null);

        $this->assertSame(['Not started' => 1], $this->slices());
    }

    public function test_nothing_is_silently_dropped(): void
    {
        // A chart that quietly leaves orders out is worse than a grey wedge.
        foreach (['Sewing', 'Printer', 'Pairing', 'Quality control', 'Inventory',
            'Laser cutting', 'Roller press', 'Export', 'Layout', 'Final mockup'] as $d) {
            $this->orderAt($d);
        }

        $leader = User::factory()->create(['job_role' => User::ROLE_LEADER, 'is_active' => true]);
        $page = $this->actingAs($leader)->get('/dashboard')->assertOk();

        $this->assertSame(
            $page->viewData('stepTotal'),
            collect($page->viewData('stepSlices'))->sum('value'),
            'the wedges must add up to the number in the middle'
        );
    }

    public function test_the_chart_is_titled_for_what_it_shows(): void
    {
        $this->orderAt('Sewing');

        $leader = User::factory()->create(['job_role' => User::ROLE_LEADER, 'is_active' => true]);

        $this->actingAs($leader)->get('/dashboard')
            ->assertOk()
            ->assertSee('Orders by current step');
    }
}
