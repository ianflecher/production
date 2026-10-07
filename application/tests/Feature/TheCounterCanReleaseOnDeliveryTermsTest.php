<?php

namespace Tests\Feature;

use App\Models\ProductionOrder;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The counter can hand over a job that pays on delivery.
 *
 * A downpayment waiver means the client pays as they receive. The balance is
 * the arrangement, not an obstacle - and this page asked only "is it fully
 * paid", so it held those orders at the counter over money nobody had ever
 * intended to collect first. Twenty-eight orders on the system carry the
 * waiver.
 *
 * The button it hid posts to tasks.approve, which has allowed delivery terms
 * all along. So the release was permitted and the page would not offer it: the
 * only way past was a leader override written up as if something had gone
 * wrong.
 *
 * An ordinary unpaid order is still refused. That part was right.
 */
class TheCounterCanReleaseOnDeliveryTermsTest extends TestCase
{
    use RefreshDatabase;

    private function orderWaitingAtTheCounter(bool $waived, float $paid = 0): ProductionOrder
    {
        $officer = User::factory()->create(['job_role' => User::ROLE_SALES, 'is_active' => true]);

        $order = ProductionOrder::create([
            'order_number' => 'IC2026-0'.random_int(1000, 9999),
            'customer_name' => 'Stephanie Moto',
            'product_type' => 'round_neck',
            'quantity' => 830,
            'due_date' => now()->addWeeks(2),
            'created_by' => $officer->id,
            'status' => 'active',
            'total_price' => 290500,
            'downpayment_waived' => $waived,
        ]);

        Task::create([
            'production_order_id' => $order->id,
            'department' => 'Release to client',
            // The counter sees it once it has been submitted for handover,
            // not merely released to the floor - see ProductInventoryController.
            'sequence' => 20, 'stage' => 12, 'status' => 'for_checking',
            'submitted_at' => now(),
            'team' => 'Inventory', 'approver_role' => 'inventory',
        ]);

        return $order->fresh();
    }

    private function counter(): User
    {
        return User::factory()->create(['job_role' => 'Inventory', 'is_active' => true]);
    }

    private function page(): string
    {
        return $this->actingAs($this->counter())
            ->get(route('products.index'))->assertOk()->getContent();
    }

    /** The case on screen: owed in full, and still releasable. */
    public function test_a_pay_on_delivery_order_can_be_released(): void
    {
        $this->orderWaitingAtTheCounter(waived: true);

        $html = $this->page();

        $this->assertStringContainsString('Released to client', $html,
            'the counter is still blocked on an order meant to pay on delivery');
        $this->assertStringNotContainsString('Cannot release', $html);
    }

    /** And it says what to collect, because the counter collects it. */
    public function test_it_says_what_to_collect_at_the_counter(): void
    {
        $html = $this->page();
        $this->orderWaitingAtTheCounter(waived: true);

        $html = $this->page();

        $this->assertStringContainsString('COLLECT', $html);
        $this->assertStringContainsString('290,500.00', $html);
        $this->assertStringContainsString('Pays on delivery', $html);
    }

    /** An ordinary unpaid order is still held. */
    public function test_an_ordinary_unpaid_order_is_still_refused(): void
    {
        $this->orderWaitingAtTheCounter(waived: false);

        $html = $this->page();

        $this->assertStringContainsString('Cannot release', $html);
        $this->assertStringNotContainsString('Released to client', $html);
    }

    /* ---------------- the order number ---------------- */

    /** It opens the pack, on both tables. */
    public function test_the_order_number_opens_the_tech_pack(): void
    {
        $order = $this->orderWaitingAtTheCounter(waived: true);

        $html = $this->page();

        $this->assertStringContainsString(route('orders.package', $order), $html,
            'the number does not open the pack the desk needs to read');
    }
}
