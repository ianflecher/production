<?php

namespace Tests\Feature;

use App\Models\Payment;
use App\Models\ProductionOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Money waiting on the finance desk says so, in the sidebar and on the desk.
 *
 * An officer records what the client says they have sent; the shop does not
 * draw on it until finance agrees it landed. Until then the job is shut - the
 * designing board calls that gap "Waiting for finance" - and the only way to
 * find it was to page through every payment the shop had ever taken.
 *
 * The badge, the dashboard card and the ledger all count through one scope on
 * purpose. A badge that counts a different thing from the page it opens is
 * worse than no badge: it sends somebody looking for work that is not there.
 */
class TheFinanceDeskIsToldWhatIsWaitingTest extends TestCase
{
    use RefreshDatabase;

    private function finance(): User
    {
        return User::factory()->create(['job_role' => User::ROLE_FINANCE, 'is_active' => true]);
    }

    private function order(array $extra = []): ProductionOrder
    {
        return ProductionOrder::create(array_merge([
            'order_number' => 'IC2026-FIN'.random_int(1000, 9999),
            'customer_name' => 'Finance Client',
            'product_type' => 'round_neck',
            'quantity' => 10,
            'unit_price' => 500,
            'due_date' => now()->addWeeks(2),
            'created_by' => User::factory()->create(['job_role' => User::ROLE_SALES, 'is_active' => true])->id,
            'status' => 'active',
        ], $extra));
    }

    private function payment(ProductionOrder $order, ?string $confirmedAt = null): Payment
    {
        return Payment::create([
            'production_order_id' => $order->id,
            'amount' => 2500,
            'kind' => 'downpayment',
            'status' => $confirmedAt ? 'confirmed' : 'pending',
            'paid_at' => now(),
            'confirmed_at' => $confirmedAt,
        ]);
    }

    public function test_the_sidebar_carries_the_count(): void
    {
        $this->payment($this->order());
        $this->payment($this->order());
        $this->payment($this->order(), confirmedAt: now());   // already agreed

        $this->actingAs($this->finance())->get(route('dashboard'))
            ->assertOk()
            ->assertSee('payment(s) waiting to be confirmed');

        $this->assertSame(2, Payment::awaitingConfirmation()->count());
    }

    /** Nothing waiting, no badge. A zero in a red circle is just noise. */
    public function test_there_is_no_badge_when_nothing_is_waiting(): void
    {
        $this->payment($this->order(), confirmedAt: now());

        $this->actingAs($this->finance())->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('payment(s) waiting to be confirmed');
    }

    /**
     * The badge and the page it opens are the same number, counted by the
     * same scope. A cancelled job's money is not work waiting on anybody, and
     * the desk has always left those out.
     */
    public function test_a_cancelled_jobs_money_is_counted_by_neither(): void
    {
        $this->payment($this->order());
        $cancelled = $this->order(['status' => 'cancelled']);
        $cancelledNumber = $cancelled->order_number;
        $this->payment($cancelled);

        $this->assertSame(1, Payment::awaitingConfirmation()->count());

        // The panel lists the live one and not the cancelled one.
        $this->actingAs($this->finance())->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Waiting to confirm')
            ->assertDontSee($cancelledNumber, false);
    }

    /** The desk is told HOW MANY, not just shown a list to count. */
    public function test_the_dashboard_says_the_number(): void
    {
        $this->payment($this->order());
        $this->payment($this->order());
        $this->payment($this->order(), confirmedAt: now());

        $page = $this->actingAs($this->finance())->get(route('dashboard'))->assertOk()->getContent();

        $this->assertStringContainsString('Waiting to confirm', $page);
        $this->assertSame(2, Payment::awaitingConfirmation()->count());
        // The panel's own badge carries it.
        $this->assertMatchesRegularExpression('#Waiting to confirm.{0,400}>2<#s', $page);
    }

    /** Somebody who cannot confirm is not offered the count. */
    public function test_the_badge_belongs_to_the_desk_that_can_act_on_it(): void
    {
        $this->payment($this->order());

        $officer = User::factory()->create(['job_role' => User::ROLE_SALES, 'is_active' => true]);

        $this->assertFalse($officer->canConfirmPayments());

        $this->actingAs($officer)->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('payment(s) waiting to be confirmed');
    }
}
