<?php

namespace Tests\Feature;

use App\Http\Controllers\BottleneckReportController as Report;
use App\Models\ProductionOrder;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The report a leader walks in wanting: what is holding us up?
 *
 * The station board says what every machine is doing and the calendar says
 * what is due. Neither says which part of the shop everything is queuing
 * behind, or which job has sat untouched the longest.
 */
class BottleneckReportTest extends TestCase
{
    use RefreshDatabase;

    private function leader(): User
    {
        return User::factory()->create(['job_role' => User::ROLE_LEADER, 'is_active' => true]);
    }

    private function order(string $number = null, string $status = 'active'): ProductionOrder
    {
        $sales = User::factory()->create(['job_role' => User::ROLE_SALES, 'is_active' => true]);

        return ProductionOrder::create([
            'order_number' => $number ?? 'IC2026-0'.random_int(1000, 9999),
            'customer_name' => 'Queue Co', 'product_type' => 'round_neck',
            'quantity' => 10, 'due_date' => now()->addWeek(),
            'created_by' => $sales->id, 'status' => $status,
        ]);
    }

    private int $seq = 0;

    private function step(ProductionOrder $order, string $department, array $attrs = []): Task
    {
        return Task::create(array_merge([
            'production_order_id' => $order->id,
            'department' => $department,
            'sequence' => ++$this->seq,
            'stage' => 3,
            'status' => 'ready',
        ], $attrs));
    }

    public function test_the_longest_wait_is_listed_first(): void
    {
        $order = $this->order('IC2026-07001');
        $this->step($order, 'Sewing', ['released_at' => now()->subDays(2)]);
        $this->step($order, 'Printer', ['released_at' => now()->subDays(9)]);

        $stuck = $this->actingAs($this->leader())->get('/reports/bottlenecks')
            ->assertOk()
            ->viewData('stuck');

        $this->assertSame('Printer', $stuck->first()['task']->department);
        $this->assertSame(9, $stuck->first()['days']);
    }

    public function test_a_step_nobody_has_reached_yet_is_not_called_stuck(): void
    {
        // TODO steps have not been released, so nobody is sitting on them.
        $order = $this->order();
        $this->step($order, 'Inventory', ['status' => 'todo', 'released_at' => null]);

        $this->assertEmpty(
            $this->actingAs($this->leader())->get('/reports/bottlenecks')->viewData('stuck')
        );
    }

    public function test_a_parked_job_is_not_mixed_in_with_the_stuck_ones(): void
    {
        // A step on a cancelled order is not stuck, it is over — and rows like
        // that bury the ones worth chasing.
        $dead = $this->order('IC2026-07002', 'cancelled');
        $this->step($dead, 'Sewing', ['released_at' => now()->subDays(30)]);

        $this->assertEmpty(
            $this->actingAs($this->leader())->get('/reports/bottlenecks')->viewData('stuck')
        );
    }

    public function test_the_slowest_step_on_average_comes_top(): void
    {
        $order = $this->order();

        $this->step($order, 'Laser cutting', [
            'status' => 'complete',
            'released_at' => now()->subDays(10), 'approved_at' => now()->subDays(10)->addHours(2),
        ]);
        $this->step($order, 'Sewing', [
            'status' => 'complete',
            'released_at' => now()->subDays(10), 'approved_at' => now()->subDays(10)->addHours(40),
        ]);

        $slowest = $this->actingAs($this->leader())->get('/reports/bottlenecks')
            ->assertOk()
            ->viewData('slowest');

        $this->assertSame('Sewing', $slowest->first()['department']);
        $this->assertEqualsWithDelta(40, $slowest->first()['average'], 0.1);
    }

    public function test_the_typical_job_is_reported_next_to_the_average(): void
    {
        // One disaster among quick jobs drags the average but not the middle —
        // which is the difference between "buy a machine" and "find that job".
        $order = $this->order();

        foreach ([1, 1, 1, 200] as $hours) {
            $this->step($order, 'Printer', [
                'status' => 'complete',
                'released_at' => now()->subDays(5),
                'approved_at' => now()->subDays(5)->addHours($hours),
            ]);
        }

        $row = $this->actingAs($this->leader())->get('/reports/bottlenecks')
            ->viewData('slowest')->first();

        $this->assertGreaterThan(40, $row['average'], 'the outlier should drag the average');
        $this->assertSame(1, (int) $row['median'], 'but the typical job is still one hour');
        $this->assertSame(200, (int) $row['worst']);
    }

    public function test_work_older_than_the_window_is_left_out(): void
    {
        $order = $this->order();
        $this->step($order, 'Pairing', [
            'status' => 'complete',
            'released_at' => now()->subDays(200), 'approved_at' => now()->subDays(199),
        ]);

        $this->assertEmpty(
            $this->actingAs($this->leader())->get('/reports/bottlenecks')->viewData('slowest')
        );
    }

    public function test_the_report_is_only_for_leaders(): void
    {
        $sewer = User::factory()->create(['job_role' => 'Sewing', 'is_active' => true]);
        $this->actingAs($sewer)->get('/reports/bottlenecks')->assertForbidden();

        $sales = User::factory()->create(['job_role' => User::ROLE_SALES, 'is_active' => true]);
        $this->actingAs($sales)->get('/reports/bottlenecks')->assertForbidden();
    }

    public function test_a_supervisor_can_open_it(): void
    {
        $supervisor = User::factory()->create(['job_role' => 'Supervisor', 'is_active' => true]);

        $this->actingAs($supervisor)->get('/reports/bottlenecks')->assertOk();
    }

    public function test_durations_read_like_a_person_wrote_them(): void
    {
        $this->assertSame('30 min', Report::forHumans(0.5));
        $this->assertSame('6 hr', Report::forHumans(6));
        $this->assertSame('3 days', Report::forHumans(72));
    }
}
