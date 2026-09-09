<?php

namespace Tests\Feature;

use App\Models\ProductionOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The cutting method can be changed until somebody starts cutting.
 *
 * It was locked by anything that "routes" the job, which included the press -
 * and the press runs BEFORE cutting. So an order sitting at cutting, with
 * nothing cut, refused to change its cutting method and told the officer
 * "cutting has already been done on this order". It had not been. IC2026-00002
 * was in exactly that state: Small press complete, both cutting steps still
 * waiting.
 *
 * The press still locks the press. Only cutting locks cutting.
 */
class TheCuttingCanChangeUntilCuttingStartsTest extends TestCase
{
    use RefreshDatabase;

    private function orderAtStageThree(): ProductionOrder
    {
        $officer = User::factory()->create(['job_role' => User::ROLE_SALES, 'is_active' => true]);

        $order = ProductionOrder::create([
            'order_number' => 'IC2026-CUT'.random_int(100, 999),
            'customer_name' => 'Cutting Client',
            'product_type' => 'round_neck',
            'quantity' => 40,
            'due_date' => now()->addWeeks(3),
            'created_by' => $officer->id,
            'status' => 'active',
            'cutting_type' => 'manual',
        ]);

        $order->jobOrder()->create([
            'status' => 'sent_to_artist', 'created_by' => $officer->id,
            'print_type' => 'dtf', 'printer' => 'dtf_printer', 'fabric_press' => 'small_press',
        ]);

        $order->buildPipeline([], 'manual');

        return $order->fresh();
    }

    public function test_a_finished_press_no_longer_locks_the_cutting_method(): void
    {
        $order = $this->orderAtStageThree();

        // The press has run. Cutting has not.
        $order->tasks()->where('department', 'Small press')->update(['status' => 'complete']);

        $this->assertTrue($order->fresh()->canEditCutting(), 'the press runs before cutting');
        // The press itself is still settled - that is a different question.
        $this->assertFalse($order->fresh()->canEditRouting());
    }

    public function test_starting_to_cut_settles_how_it_is_cut(): void
    {
        $order = $this->orderAtStageThree();

        $order->tasks()->where('stage', 5)->update(['status' => 'in_progress']);

        $this->assertFalse($order->fresh()->canEditCutting());
    }

    public function test_the_officer_can_swap_the_cutting_after_the_press_has_run(): void
    {
        $order = $this->orderAtStageThree();
        $order->tasks()->where('department', 'Small press')->update(['status' => 'complete']);

        $officer = User::find($order->created_by);

        $this->actingAs($officer)->post(route('job-orders.production.update', $order), [
            'raw_materials' => ['Cotton'],
            'fabric_press' => 'small_press',
            'cutting_type' => 'laser',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $order = $order->fresh();

        $this->assertSame('laser', $order->cutting_type);
        $this->assertSame(0, $order->tasks()->where('department', 'Manual cutting')->count());
        $this->assertSame(2, $order->tasks()->where('department', 'Laser cutting')->count());
        // The finished press is untouched - this swaps cutting and nothing else.
        $this->assertSame('complete', $order->tasks()->where('department', 'Small press')->value('status'));
    }

    public function test_a_started_cut_is_refused_and_the_reason_names_itself(): void
    {
        $order = $this->orderAtStageThree();
        $order->tasks()->where('stage', 5)->update(['status' => 'in_progress']);

        $officer = User::find($order->created_by);

        $this->actingAs($officer)->post(route('job-orders.production.update', $order), [
            'raw_materials' => ['Cotton'],
            'fabric_press' => 'small_press',
            'cutting_type' => 'laser',
        ])->assertRedirect();

        // Still manual, and the message says what actually stopped it.
        $this->assertSame('manual', $order->fresh()->cutting_type);
        $this->assertStringContainsString('manual cutting is already under way', session('success'));
    }

    public function test_work_already_done_is_never_thrown_away(): void
    {
        // The swap only removes cutting steps nobody has started.
        $order = $this->orderAtStageThree();

        $order->tasks()->where('stage', 11)->update(['status' => 'complete']);

        $this->assertFalse($order->fresh()->canEditCutting());

        $order->fresh()->changeCuttingTo('laser');

        $this->assertSame('manual', $order->fresh()->cutting_type);
        $this->assertSame('complete', $order->tasks()->where('stage', 11)->value('status'));
    }
}
