<?php

namespace Tests\Feature;

use App\Models\Inquiry;
use App\Models\InquiryDesign;
use App\Models\Payment;
use App\Models\ProductionOrder;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * One payment can cover every job under a job number.
 *
 * One brief becomes one job number and as many orders as it has designs — the
 * floor runs them as one job. Money does not work that way: hasDownpayment()
 * asks one order about its own payments, and nothing anywhere adds them up
 * across a number. So a client with three designs who pays once had it
 * recorded against one order, that one opened, and the other two sat waiting
 * for money that had already arrived. Nothing reports that, either —
 * orders:stalled only looks at jobs whose money IS settled.
 *
 * Philander Dela Cruz is the live case: three approved designs on inquiry #26,
 * one order written so far, and one payment coming for all of them.
 *
 * The reference and the proof are deliberately the SAME on every row. It was
 * one transfer, and that is what Finance reconciles against the statement.
 */
class OnePaymentCoversTheWholeJobNumberTest extends TestCase
{
    use RefreshDatabase;

    private function officer(): User
    {
        return User::factory()->create(['job_role' => User::ROLE_SALES, 'is_active' => true]);
    }

    /**
     * Three designs on one brief, written up as three orders that all carry
     * the same job number. Layouts approved, nothing paid.
     *
     * @return array{0: User, 1: \Illuminate\Support\Collection<int, ProductionOrder>}
     */
    private function threeJobsOnOneNumber(array $prices = [4500, 3000, 1500], string $number = 'IC2026-01232'): array
    {
        $officer = $this->officer();
        $artist = User::factory()->create(['job_role' => User::JOB_ARTIST, 'is_active' => true]);

        $client = \App\Models\Client::create([
            'name' => 'Philander', 'last_name' => 'Dela Cruz',
            'contact_number' => '0917-000-0000', 'created_by' => $officer->id,
        ]);

        $inquiry = Inquiry::create([
            'client_id' => $client->id,
            'what_they_want' => 'Three shirts',
            'created_by' => $officer->id,
        ]);

        $orders = collect($prices)->values()->map(function ($price, $i) use ($officer, $artist, $inquiry, $client, $number) {
            $design = InquiryDesign::create([
                'inquiry_id' => $inquiry->id,
                'position' => $i,
                'label' => 'DESIGN '.($i + 1),
                'status' => InquiryDesign::STATUS_APPROVED,
            ]);

            $order = ProductionOrder::create([
                // The same number on purpose: one brief, one job number.
                'order_number' => $number,
                'customer_name' => 'Philander Dela Cruz',
                'client_id' => $client->id,
                'inquiry_id' => $inquiry->id,
                'inquiry_design_id' => $design->id,
                'product_type' => 'round_neck',
                'quantity' => 6,
                'total_price' => $price,
                'due_date' => now()->addWeeks(3),
                'created_by' => $officer->id,
                'status' => 'active',
            ]);

            // The downpayment is only collected once the layout is approved.
            Task::create([
                'production_order_id' => $order->id,
                'department' => 'Layout',
                'team' => User::JOB_ARTIST,
                'stage' => 1,
                'sequence' => 1,
                'status' => 'complete',
                'approved_at' => now(),
                'assigned_to' => $artist->id,
            ]);

            return $order->fresh();
        });

        return [$officer, $orders];
    }

    private function pay(User $officer, ProductionOrder $on, array $extra = []): \Illuminate\Testing\TestResponse
    {
        Storage::fake('local');

        return $this->actingAs($officer)->post(route('orders.payment', $on), array_merge([
            'portion' => 'half',
            'method' => 'GCash',
            'reference' => 'GC-778899',
            'proof' => UploadedFile::fake()->image('gcash.jpg'),
        ], $extra));
    }

    /* ---------------- the split ---------------- */

    /** Proportional to what each owes, and it adds up to what was handed over. */
    public function test_it_divides_the_payment_by_what_each_job_owes(): void
    {
        [$officer, $orders] = $this->threeJobsOnOneNumber([4500, 3000, 1500]);

        // Half of the combined 9,000.
        $this->pay($officer, $orders->first(), ['covers_all' => 1])
            ->assertSessionHasNoErrors();

        $paid = $orders->map(fn ($o) => (float) $o->fresh()->payments()->sum('amount'));

        $this->assertSame([2250.0, 1500.0, 750.0], $paid->all());
        $this->assertSame(4500.0, $paid->sum());
    }

    /** Every job gets a row, so every job's own gate can open. */
    public function test_every_job_under_the_number_gets_its_own_payment(): void
    {
        [$officer, $orders] = $this->threeJobsOnOneNumber();

        $this->pay($officer, $orders->first(), ['covers_all' => 1]);

        foreach ($orders as $order) {
            $this->assertSame(1, $order->fresh()->payments()->count(),
                'a job under this number was left with no payment');
        }
    }

    /**
     * One transfer, one reference, one screenshot — on every row. That is
     * what makes three rows reconcile against one line on the statement.
     */
    public function test_the_reference_and_proof_are_the_same_on_every_row(): void
    {
        [$officer, $orders] = $this->threeJobsOnOneNumber();

        $this->pay($officer, $orders->first(), ['covers_all' => 1]);

        $payments = Payment::all();

        $this->assertCount(3, $payments);
        $this->assertCount(1, $payments->pluck('reference')->unique());
        $this->assertCount(1, $payments->pluck('proof_path')->unique());
        $this->assertSame('GC-778899', $payments->first()->reference);
    }

    /** Rounding lands on the last share rather than going missing. */
    public function test_the_shares_add_up_exactly(): void
    {
        [$officer, $orders] = $this->threeJobsOnOneNumber([1000, 1000, 1000]);

        // Half of 3,000 is 1,500 — which does not divide into three by
        // thirds without a remainder.
        $this->pay($officer, $orders->first(), ['covers_all' => 1]);

        $this->assertSame(1500.0, (float) Payment::sum('amount'));
    }

    /* ---------------- and it stays opt-in ---------------- */

    /** Unticked, it pays this one job only — exactly as before. */
    public function test_without_the_box_only_this_job_is_paid(): void
    {
        [$officer, $orders] = $this->threeJobsOnOneNumber();

        $this->pay($officer, $orders->first())->assertSessionHasNoErrors();

        $this->assertSame(1, $orders[0]->fresh()->payments()->count());
        $this->assertSame(0, $orders[1]->fresh()->payments()->count());
        $this->assertSame(0, $orders[2]->fresh()->payments()->count());
    }

    /** An order that is the only one on its number is unaffected either way. */
    public function test_a_lone_job_is_unaffected(): void
    {
        [$officer, $orders] = $this->threeJobsOnOneNumber([4500], 'IC2026-01298');

        $this->assertFalse($orders->first()->sharesItsNumber());

        $this->pay($officer, $orders->first(), ['covers_all' => 1])
            ->assertSessionHasNoErrors();

        $this->assertSame(2250.0, (float) Payment::sum('amount'));
    }

    /* ---------------- what it refuses, and why ---------------- */

    /**
     * An unpriced sibling cannot take a share, and guessing one would put a
     * number on the books nobody decided. The refusal names which job.
     */
    public function test_it_refuses_when_a_job_under_the_number_has_no_price(): void
    {
        [$officer, $orders] = $this->threeJobsOnOneNumber();
        $orders[1]->update(['total_price' => null]);

        $this->pay($officer, $orders->first(), ['covers_all' => 1])
            ->assertSessionHasErrors('payment');

        $this->assertStringContainsString('DESIGN 2', session('errors')->first('payment'));
        $this->assertSame(0, Payment::count());
    }

    /** Nothing is paid for before its layout is approved. */
    public function test_it_refuses_when_a_job_has_no_approved_layout(): void
    {
        [$officer, $orders] = $this->threeJobsOnOneNumber();
        $orders[2]->tasks()->where('department', 'Layout')->update(['status' => 'in_progress', 'approved_at' => null]);

        $this->pay($officer, $orders->first(), ['covers_all' => 1])
            ->assertSessionHasErrors('payment');

        $this->assertStringContainsString('DESIGN 3', session('errors')->first('payment'));
        $this->assertSame(0, Payment::count());
    }

    /** And nothing is recorded twice while Finance is still looking. */
    public function test_it_refuses_when_a_sibling_is_already_waiting_on_finance(): void
    {
        [$officer, $orders] = $this->threeJobsOnOneNumber();

        $orders[1]->recordPayment([
            'amount' => 100, 'method' => 'GCash', 'reference' => 'X',
            'recorded_by' => $officer->id,
        ]);

        $this->pay($officer, $orders->first(), ['covers_all' => 1])
            ->assertSessionHasErrors('payment');

        $this->assertSame(1, Payment::count(), 'it recorded on top of a payment Finance has not seen');
    }

    /* ---------------- what it tells the officer ---------------- */

    public function test_it_says_how_the_money_was_divided(): void
    {
        [$officer, $orders] = $this->threeJobsOnOneNumber([4500, 3000, 1500]);

        $this->pay($officer, $orders->first(), ['covers_all' => 1]);

        $said = session('success');

        $this->assertStringContainsString('Split across 3 jobs', $said);
        $this->assertStringContainsString('DESIGN 1 ₱2,250.00', $said);
        $this->assertStringContainsString('DESIGN 3 ₱750.00', $said);
    }

    /** The box is only offered when there is more than one job to cover. */
    public function test_the_box_is_offered_only_when_the_number_is_shared(): void
    {
        [$officer, $orders] = $this->threeJobsOnOneNumber();

        $this->actingAs($officer)
            ->get(route('orders.show', $orders->first()))
            ->assertOk()
            ->assertSee('covers all 3 jobs under IC2026-01232');

        // A different number, or it is not lone at all - it would be a fourth
        // job under the same one.
        [$officer2, $lone] = $this->threeJobsOnOneNumber([4500], 'IC2026-01299');

        $this->actingAs($officer2)
            ->get(route('orders.show', $lone->first()))
            ->assertOk()
            ->assertDontSee('covers all');
    }
}
