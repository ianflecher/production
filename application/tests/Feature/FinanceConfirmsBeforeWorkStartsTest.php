<?php

namespace Tests\Feature;

use App\Models\ProductionOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * A payment counts once Finance says it landed.
 *
 * What the account officer records is what the client told them — a claim,
 * with a photograph of a slip attached. Finance watches the account. Until
 * they agree the money arrived, the shop does not draw: no mockup, and no
 * tech pack behind it.
 *
 * Every step's deadline is measured from that confirmation too, because that
 * is the moment the job actually starts.
 */
class FinanceConfirmsBeforeWorkStartsTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: User, 1: User, 2: ProductionOrder} */
    private function orderAwaitingMoney(): array
    {
        Storage::fake('local');

        $sales = User::factory()->create(['job_role' => User::ROLE_SALES, 'is_active' => true]);
        $finance = User::factory()->create(['job_role' => User::ROLE_FINANCE, 'is_active' => true]);

        $order = ProductionOrder::create([
            'order_number' => 'IC2026-PAID', 'customer_name' => 'Paying Co',
            'product_type' => 'round_neck', 'quantity' => 20, 'unit_price' => 350,
            'due_date' => now()->addWeeks(4), 'created_by' => $sales->id, 'status' => 'active',
        ]);

        $order->items()->create(['size' => 'M', 'quantity' => 20]);
        $order->jobOrder()->create(['status' => 'draft', 'created_by' => $sales->id]);
        $order->refresh()->recomputeTotal();
        $order->refresh()->buildPipeline([], 'manual');

        // The layout is drawn and approved; the mockup is what the money buys.
        $order->unlockStage(1);
        $layout = $order->tasks()->where('department', 'Layout')->firstOrFail();
        $layout->update(['status' => 'complete', 'approved_at' => now()]);
        $order->refresh()->handleTaskCompleted($layout->fresh());

        return [$sales, $finance, $order->refresh()];
    }

    private function record(User $sales, ProductionOrder $order): void
    {
        $this->actingAs($sales)->post(route('orders.payment', $order), [
            'portion' => 'half', 'method' => 'Cash',
            'proof' => UploadedFile::fake()->image('slip.jpg'),
        ]);
    }

    public function test_a_recorded_payment_is_not_money_yet(): void
    {
        [$sales, , $order] = $this->orderAwaitingMoney();

        $this->record($sales, $order);

        $order->refresh();

        $this->assertFalse($order->hasDownpayment(), 'a claim counted as money');
        $this->assertTrue($order->hasPaymentAwaitingFinance());
        $this->assertSame(
            'todo',
            $order->tasks()->where('department', 'Final mockup')->value('status'),
            'the artist was set to work on an unconfirmed payment'
        );
    }

    public function test_confirming_it_starts_the_job(): void
    {
        [$sales, $finance, $order] = $this->orderAwaitingMoney();

        $this->record($sales, $order);
        $payment = $order->fresh()->payments()->firstOrFail();

        $this->actingAs($finance)->post(route('finance.confirm', $payment))->assertRedirect();

        $order->refresh();

        $this->assertTrue($order->hasDownpayment());
        $this->assertFalse($order->hasPaymentAwaitingFinance());
        $this->assertSame(
            'ready',
            $order->tasks()->where('department', 'Final mockup')->value('status')
        );
    }

    public function test_the_officer_cannot_confirm_their_own(): void
    {
        // A claim and its confirmation from one person is no confirmation.
        [$sales, , $order] = $this->orderAwaitingMoney();

        $this->record($sales, $order);
        $payment = $order->fresh()->payments()->firstOrFail();

        $this->actingAs($sales)->post(route('finance.confirm', $payment))->assertForbidden();

        $this->assertFalse($payment->fresh()->isConfirmed());
    }

    public function test_confirming_twice_changes_nothing(): void
    {
        [$sales, $finance, $order] = $this->orderAwaitingMoney();

        $this->record($sales, $order);
        $payment = $order->fresh()->payments()->firstOrFail();

        $this->actingAs($finance)->post(route('finance.confirm', $payment));
        $first = $payment->fresh()->confirmed_at;

        $this->actingAs($finance)->post(route('finance.confirm', $payment));

        $this->assertEquals($first, $payment->fresh()->confirmed_at, 'the second press moved the date');
    }

    public function test_the_confirmation_is_signed(): void
    {
        [$sales, $finance, $order] = $this->orderAwaitingMoney();

        $this->record($sales, $order);
        $payment = $order->fresh()->payments()->firstOrFail();

        $this->actingAs($finance)->post(route('finance.confirm', $payment));

        $this->assertSame($finance->id, $payment->fresh()->confirmed_by);
        $this->assertSame($finance->name, $payment->fresh()->confirmer->name);
    }

    public function test_money_taken_before_this_existed_still_counts(): void
    {
        // The migration confirmed everything already recorded. Jobs mid-flight
        // must not stop because a gate appeared behind them.
        [$sales, , $order] = $this->orderAwaitingMoney();

        $this->record($sales, $order);
        $order->fresh()->payments()->update(['confirmed_at' => now()->subMonth()]);

        $this->assertTrue($order->fresh()->hasDownpayment());
    }
}
