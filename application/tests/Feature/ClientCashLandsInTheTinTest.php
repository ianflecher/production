<?php

namespace Tests\Feature;

use App\Models\Expense;
use App\Models\Payment;
use App\Models\PettyCashTopup;
use App\Models\ProductionOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * A client paying in cash puts money in the tin.
 *
 * The petty cash tin was everything somebody walked to the bank for, less
 * everything spent from it. Cash a client handed over went into the same
 * drawer and was counted by nobody, so the notes and the screen disagreed by
 * however much had come in that way - and the balance is what decides whether
 * an expense may be recorded against the tin, so the shop was refused its own
 * money.
 *
 * Nothing is duplicated to make this work. The tin has never kept its own
 * ledger - what comes out of it is an ordinary expense paid with petty cash -
 * and what comes in is now read the same way, off the payments themselves.
 * Writing a matching top-up row instead would be two records of one event,
 * and they drift the first time one is corrected.
 *
 * The care is in WHICH cash counts: confirmed only.
 */
class ClientCashLandsInTheTinTest extends TestCase
{
    use RefreshDatabase;

    private function finance(): User
    {
        return User::factory()->create(['job_role' => 'finance', 'is_active' => true]);
    }

    private function order(User $officer): ProductionOrder
    {
        return ProductionOrder::create([
            'order_number' => 'IC2026-0'.random_int(1000, 9999),
            'customer_name' => 'Cash Client',
            'product_type' => 'round_neck',
            'quantity' => 20,
            'due_date' => now()->addWeeks(2),
            'created_by' => $officer->id,
            'status' => 'active',
        ]);
    }

    private function payment(User $officer, array $extra = []): Payment
    {
        return Payment::create(array_merge([
            'production_order_id' => $this->order($officer)->id,
            'amount' => 5000,
            'method' => Payment::METHOD_CASH,
            'kind' => 'downpayment',
            'paid_at' => now(),
            'confirmed_at' => now(),
            'confirmed_by' => $officer->id,
            'recorded_by' => $officer->id,
        ], $extra));
    }

    /* ---------------- the string the whole thing hangs on ---------------- */

    /**
     * The one that would go wrong quietly. The tin matches on this exact
     * value; rename the method in the list without it and the tin counts
     * nothing, with no error anywhere - found only when the drawer and the
     * screen disagree.
     */
    public function test_the_cash_method_is_still_one_of_the_payment_methods(): void
    {
        $this->assertContains(Payment::METHOD_CASH, Payment::METHODS);
        $this->assertSame('Cash', Payment::METHOD_CASH);
    }

    /* ---------------- what counts ---------------- */

    public function test_a_confirmed_cash_downpayment_is_money_in_the_tin(): void
    {
        $this->payment($this->finance(), ['amount' => 5000, 'kind' => 'downpayment']);

        $this->assertSame(5000.0, PettyCashTopup::cashFromClients());
        $this->assertSame(5000.0, PettyCashTopup::totalIn());
        $this->assertSame(5000.0, PettyCashTopup::balance());
    }

    public function test_a_confirmed_cash_full_payment_is_money_in_the_tin(): void
    {
        $this->payment($this->finance(), ['amount' => 12000, 'kind' => 'full']);

        $this->assertSame(12000.0, PettyCashTopup::balance());
    }

    /**
     * And a part payment too. Cash is cash: a rule that took a downpayment
     * into the drawer but not a payment in the middle would have the tin
     * wrong the first time somebody paid that way, and a tin whose number
     * does not match the notes is not worth counting.
     */
    public function test_a_part_payment_in_cash_counts_as_well(): void
    {
        $this->payment($this->finance(), ['amount' => 800, 'kind' => 'payment']);

        $this->assertSame(800.0, PettyCashTopup::balance());
    }

    public function test_it_adds_to_what_was_topped_up_rather_than_replacing_it(): void
    {
        $finance = $this->finance();

        PettyCashTopup::create(['amount' => 1000, 'occurred_at' => now(), 'recorded_by' => $finance->id]);
        $this->payment($finance, ['amount' => 5000]);

        $this->assertSame(1000.0, PettyCashTopup::totalToppedUp());
        $this->assertSame(5000.0, PettyCashTopup::cashFromClients());
        $this->assertSame(6000.0, PettyCashTopup::totalIn());
    }

    /* ---------------- what does not ---------------- */

    /**
     * The rule that matters most. An officer-recorded payment is a claim
     * until Finance agrees - the same rule that holds the mockup back - and
     * here it decides whether the shop may spend against the tin. Counting a
     * claim lets somebody pay out money nobody has been shown.
     */
    public function test_cash_finance_has_not_confirmed_is_not_in_the_tin_yet(): void
    {
        $this->payment($this->finance(), ['amount' => 5000, 'confirmed_at' => null, 'confirmed_by' => null]);

        $this->assertSame(0.0, PettyCashTopup::cashFromClients());
        $this->assertSame(0.0, PettyCashTopup::balance());
        $this->assertSame(5000.0, PettyCashTopup::cashAwaitingConfirmation());
    }

    /** Money that arrived by any other route is somebody else's statement. */
    public function test_a_gcash_or_bank_payment_never_touches_the_tin(): void
    {
        $finance = $this->finance();

        $this->payment($finance, ['amount' => 9000, 'method' => 'GCash']);
        $this->payment($finance, ['amount' => 9000, 'method' => 'Bank transfer – UnionBank']);

        $this->assertSame(0.0, PettyCashTopup::cashFromClients());
        $this->assertSame(0.0, PettyCashTopup::balance());
    }

    /* ---------------- and it can then be spent ---------------- */

    /**
     * The point of the whole thing: the tin refused to pay out money it was
     * actually holding, because the money had come in as a cash payment.
     */
    public function test_the_tin_can_spend_the_cash_a_client_paid_in(): void
    {
        Storage::fake('local');
        $finance = $this->finance();

        $this->payment($finance, ['amount' => 5000]);

        $this->actingAs($finance)
            ->post(route('books.expenses.store'), [
                'account_title' => 'Other Expense',
                'description' => 'Fuel for the delivery',
                'amount' => 1200,
                'spent_at' => now()->toDateString(),
                'method' => Expense::METHOD_PETTY_CASH,
                'receipt' => UploadedFile::fake()->create('or.pdf', 20, 'application/pdf'),
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame(1, Expense::count());
        $this->assertSame(3800.0, PettyCashTopup::balance());
    }

    /** It is still a tin: unconfirmed cash does not raise what may be spent. */
    public function test_unconfirmed_cash_cannot_be_spent(): void
    {
        Storage::fake('local');
        $finance = $this->finance();

        $this->payment($finance, ['amount' => 5000, 'confirmed_at' => null, 'confirmed_by' => null]);

        $this->actingAs($finance)
            ->post(route('books.expenses.store'), [
                'account_title' => 'Other Expense',
                'description' => 'Fuel for the delivery',
                'amount' => 1200,
                'spent_at' => now()->toDateString(),
                'method' => Expense::METHOD_PETTY_CASH,
                'receipt' => UploadedFile::fake()->create('or.pdf', 20, 'application/pdf'),
            ])
            ->assertSessionHasErrors('amount');

        $this->assertSame(0, Expense::count());
    }

    /* ---------------- what the page says ---------------- */

    public function test_the_books_page_says_where_the_tin_money_came_from(): void
    {
        $finance = $this->finance();

        PettyCashTopup::create(['amount' => 1000, 'occurred_at' => now(), 'recorded_by' => $finance->id]);
        $this->payment($finance, ['amount' => 5000, 'kind' => 'full']);

        $this->actingAs($finance)
            ->get('/books')
            ->assertOk()
            ->assertSee('1,000.00 topped up')
            ->assertSee('5,000.00 cash from clients')
            ->assertSee('Cash from clients')
            ->assertSee('Full payment');
    }

    /**
     * The gap between the drawer and the screen is explained on the page,
     * rather than found by somebody counting the tin and deciding the system
     * is broken.
     */
    public function test_the_page_says_what_is_in_the_drawer_but_not_counted(): void
    {
        $finance = $this->finance();
        $this->payment($finance, ['amount' => 5000, 'confirmed_at' => null, 'confirmed_by' => null]);

        $this->actingAs($finance)
            ->get('/books')
            ->assertOk()
            ->assertSee('waiting for Finance to')
            ->assertSee('5,000.00 of client cash');
    }

    /** And says nothing at all when there is nothing waiting. */
    public function test_the_page_is_quiet_when_nothing_is_waiting(): void
    {
        $finance = $this->finance();
        $this->payment($finance, ['amount' => 5000]);

        $this->actingAs($finance)
            ->get('/books')
            ->assertOk()
            ->assertDontSee('of client cash is waiting');
    }
}
