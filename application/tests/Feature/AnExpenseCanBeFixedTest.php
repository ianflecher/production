<?php

namespace Tests\Feature;

use App\Models\Expense;
use App\Models\PettyCashTopup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * A row written down wrong can be put right.
 *
 * A month leaves this page as one sheet the bookkeeper reconciles against
 * bank and GCash statements, so a supplier misspelt, an amount out by a peso
 * or a date in the wrong month has to be correctable. There was no way to:
 * the only button on a row was Remove, and putting it back meant typing all
 * fifteen fields again - which nobody does at five o'clock, so the wrong
 * figure went to the books.
 *
 * The petty cash tin is the part with a trap in it. Its balance is every
 * top-up less every expense paid from it, so editing one of those expenses
 * is measured against a tin that has ALREADY counted the expense's own money
 * as gone. Checked naively, correcting a real ₱800 payment to ₱850 out of a
 * ₱1,000 tin is refused, because ₱850 is compared against the ₱200 left.
 */
class AnExpenseCanBeFixedTest extends TestCase
{
    use RefreshDatabase;

    private function finance(): User
    {
        return User::factory()->create(['job_role' => 'finance', 'is_active' => true]);
    }

    private function anExpense(array $extra = []): Expense
    {
        return Expense::create(array_merge([
            'account_title' => 'COS - Fabric',
            'description' => 'Cotton from Divisoria',
            'amount' => 4500,
            'spent_at' => '2026-09-03',
            'supplier' => 'Divisora Textles',
            'recorded_by' => User::factory()->create(['job_role' => 'finance'])->id,
        ], $extra));
    }

    /** Everything the update route insists on, so a test can vary one thing. */
    private function correction(array $extra = []): array
    {
        return array_merge([
            'account_title' => 'COS - Fabric',
            'description' => 'Cotton from Divisoria',
            'amount' => 4500,
            'spent_at' => '2026-09-03',
        ], $extra);
    }

    /* ---------------- the date box ---------------- */

    /**
     * Blank, not today.
     *
     * A date already in the box is an answer, and an answer nobody typed is
     * an answer nobody checks: things ordered last week were going in dated
     * the day they were entered, which is how an expense lands in the wrong
     * month and the month that has already gone to the bookkeeper changes.
     */
    public function test_the_order_date_starts_empty(): void
    {
        $html = $this->actingAs($this->finance())->get('/books')->assertOk()->getContent();

        $this->assertSame(1, preg_match('/<input[^>]*id="spent_at"[^>]*>/', $html, $m));
        $this->assertStringNotContainsString(now()->format('Y-m-d'), $m[0]);
        $this->assertStringContainsString('value=""', $m[0]);
    }

    /** Editing one, though, shows the date it actually has. */
    public function test_editing_one_shows_the_date_it_was_given(): void
    {
        $expense = $this->anExpense();

        $html = $this->actingAs($this->finance())
            ->get(route('books.expenses.edit', $expense))
            ->assertOk()
            ->getContent();

        $this->assertSame(1, preg_match('/<input[^>]*id="spent_at"[^>]*>/', $html, $m));
        $this->assertStringContainsString('value="2026-09-03"', $m[0]);
    }

    /* ---------------- correcting a row ---------------- */

    public function test_the_edit_page_is_the_same_form_already_filled_in(): void
    {
        $expense = $this->anExpense(['supplier' => 'Divisora Textles', 'tin' => '123-456-789-000']);

        $this->actingAs($this->finance())
            ->get(route('books.expenses.edit', $expense))
            ->assertOk()
            ->assertSee('Divisora Textles', false)
            ->assertSee('123-456-789-000', false)
            ->assertSee('Save changes');
    }

    public function test_a_row_can_be_corrected(): void
    {
        $expense = $this->anExpense();

        $this->actingAs($this->finance())
            ->post(route('books.expenses.update', $expense), $this->correction([
                'supplier' => 'Divisoria Textiles',
                'amount' => 4550,
                'account_title' => 'COS - Shirt',
                'vat_status' => 'VAT',
            ]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $expense->refresh();

        $this->assertSame('Divisoria Textiles', $expense->supplier);
        $this->assertSame(4550.0, (float) $expense->amount);
        $this->assertSame('COS - Shirt', $expense->account_title);
        $this->assertSame('VAT', $expense->vat_status);
    }

    /**
     * And it is still the row it was. Removing and re-recording lost who
     * wrote it down, which is the one thing the books are asked afterwards.
     */
    public function test_correcting_a_row_does_not_change_who_recorded_it(): void
    {
        $expense = $this->anExpense();
        $originalRecorder = $expense->recorded_by;
        $somebodyElse = $this->finance();

        $this->actingAs($somebodyElse)
            ->post(route('books.expenses.update', $expense), $this->correction(['supplier' => 'Fixed']))
            ->assertRedirect();

        $expense->refresh();

        $this->assertSame($originalRecorder, $expense->recorded_by);
        $this->assertNotSame($somebodyElse->id, $expense->recorded_by);
        $this->assertSame(1, Expense::count(), 'the correction made a second row');
    }

    /* ---------------- the rules still apply on the way back in ---------------- */

    /**
     * The rules live in one place for both forms. Two lists would drift, and
     * an edit form quietly missing a rule is how a rule stops applying
     * without anybody deciding it should.
     */
    public function test_a_made_up_account_title_is_still_refused(): void
    {
        $expense = $this->anExpense();

        $this->actingAs($this->finance())
            ->post(route('books.expenses.update', $expense),
                $this->correction(['account_title' => 'Raw materials']))
            ->assertSessionHasErrors('account_title');

        $this->assertSame('COS - Fabric', $expense->fresh()->account_title);
    }

    public function test_it_still_cannot_have_been_paid_before_it_was_ordered(): void
    {
        $expense = $this->anExpense();

        $this->actingAs($this->finance())
            ->post(route('books.expenses.update', $expense),
                $this->correction(['spent_at' => '2026-09-10', 'paid_at' => '2026-09-03']))
            ->assertSessionHasErrors('paid_at');
    }

    /** The prefix the form draws is still taken back off on the way in. */
    public function test_a_reference_is_still_stored_without_its_prefix(): void
    {
        $expense = $this->anExpense();

        $this->actingAs($this->finance())
            ->post(route('books.expenses.update', $expense),
                $this->correction(['reference_type' => 'PO', 'reference' => 'PO-0042']))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame('0042', $expense->fresh()->reference);
    }

    /* ---------------- the receipt ---------------- */

    public function test_the_receipt_on_record_is_kept_when_no_new_one_is_chosen(): void
    {
        Storage::fake('local');

        $expense = $this->anExpense([
            'receipt_path' => 'expense-receipts/original.pdf',
            'receipt_name' => 'original.pdf',
        ]);

        $this->actingAs($this->finance())
            ->post(route('books.expenses.update', $expense), $this->correction(['supplier' => 'Fixed']))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $expense->refresh();

        $this->assertSame('expense-receipts/original.pdf', $expense->receipt_path);
        $this->assertSame('original.pdf', $expense->receipt_name);
    }

    public function test_a_new_receipt_replaces_the_one_on_record(): void
    {
        Storage::fake('local');

        $expense = $this->anExpense([
            'receipt_path' => 'expense-receipts/original.pdf',
            'receipt_name' => 'original.pdf',
        ]);

        $this->actingAs($this->finance())
            ->post(route('books.expenses.update', $expense), $this->correction([
                'receipt' => UploadedFile::fake()->create('the-real-one.pdf', 20, 'application/pdf'),
            ]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $expense->refresh();

        $this->assertSame('the-real-one.pdf', $expense->receipt_name);
        $this->assertNotSame('expense-receipts/original.pdf', $expense->receipt_path);
        Storage::disk('local')->assertExists($expense->receipt_path);
    }

    /* ---------------- the petty cash trap ---------------- */

    /**
     * The one that would have gone wrong quietly.
     *
     * ₱1,000 in the tin and ₱800 already paid out of it leaves ₱200. Putting
     * that ₱800 right at ₱850 must be allowed - the money it is replacing is
     * its own - but a check written against the balance alone compares ₱850
     * to ₱200 and refuses a correction that is perfectly good.
     */
    public function test_correcting_a_petty_cash_expense_is_not_charged_for_it_twice(): void
    {
        $finance = $this->finance();
        PettyCashTopup::create(['amount' => 1000, 'occurred_at' => '2026-09-01', 'recorded_by' => $finance->id]);

        $expense = $this->anExpense(['amount' => 800, 'method' => Expense::METHOD_PETTY_CASH]);

        $this->assertSame(200.0, PettyCashTopup::balance());

        $this->actingAs($finance)
            ->post(route('books.expenses.update', $expense), $this->correction([
                'amount' => 850,
                'method' => Expense::METHOD_PETTY_CASH,
            ]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame(850.0, (float) $expense->fresh()->amount);
        $this->assertSame(150.0, PettyCashTopup::balance());
    }

    /** It is still a tin, though, and a tin cannot pay out what it never held. */
    public function test_a_correction_still_cannot_take_more_than_the_tin_ever_held(): void
    {
        $finance = $this->finance();
        PettyCashTopup::create(['amount' => 1000, 'occurred_at' => '2026-09-01', 'recorded_by' => $finance->id]);

        $expense = $this->anExpense(['amount' => 800, 'method' => Expense::METHOD_PETTY_CASH]);

        $this->actingAs($finance)
            ->post(route('books.expenses.update', $expense), $this->correction([
                'amount' => 1100,
                'method' => Expense::METHOD_PETTY_CASH,
            ]))
            ->assertSessionHasErrors('amount');

        $this->assertSame(800.0, (float) $expense->fresh()->amount);
    }

    /** Moving one ONTO petty cash is measured against the tin as it stands. */
    public function test_moving_an_expense_onto_petty_cash_is_measured_against_the_tin(): void
    {
        $finance = $this->finance();
        PettyCashTopup::create(['amount' => 1000, 'occurred_at' => '2026-09-01', 'recorded_by' => $finance->id]);

        $expense = $this->anExpense(['amount' => 1500, 'method' => 'Bank Tranfer (AUB)']);

        $this->actingAs($finance)
            ->post(route('books.expenses.update', $expense), $this->correction([
                'amount' => 1500,
                'method' => Expense::METHOD_PETTY_CASH,
            ]))
            ->assertSessionHasErrors('amount');

        $this->assertSame('Bank Tranfer (AUB)', $expense->fresh()->method);
    }

    /* ---------------- top-ups ---------------- */

    private function topUp(float $amount, string $on = '2026-09-01'): PettyCashTopup
    {
        return PettyCashTopup::create([
            'amount' => $amount,
            'occurred_at' => $on,
            'recorded_by' => User::factory()->create(['job_role' => 'finance'])->id,
        ]);
    }

    public function test_a_top_up_can_be_corrected(): void
    {
        $topup = $this->topUp(5000);

        $this->actingAs($this->finance())
            ->post(route('books.petty-cash.update', $topup), [
                'amount' => 500,
                'occurred_at' => '2026-09-02',
                'note' => 'Typed with an extra nought',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $topup->refresh();

        $this->assertSame(500.0, (float) $topup->amount);
        $this->assertSame('2026-09-02', $topup->occurred_at->toDateString());
        $this->assertSame('Typed with an extra nought', $topup->note);
        $this->assertSame(500.0, PettyCashTopup::balance());
    }

    /**
     * A tin cannot be told it holds less than has already come out of it.
     * The balance is what the page, the guard on new expenses and the person
     * counting the notes all read, so it must never go below nothing.
     */
    public function test_a_top_up_cannot_be_lowered_below_what_has_been_spent(): void
    {
        $topup = $this->topUp(1000);
        $this->anExpense(['amount' => 800, 'method' => Expense::METHOD_PETTY_CASH]);

        $this->actingAs($this->finance())
            ->post(route('books.petty-cash.update', $topup), [
                'amount' => 500,
                'occurred_at' => '2026-09-01',
            ])
            ->assertSessionHasErrors('amount');

        $this->assertSame(1000.0, (float) $topup->fresh()->amount);
        $this->assertSame(200.0, PettyCashTopup::balance());
    }

    public function test_a_top_up_can_be_taken_back_out(): void
    {
        $this->topUp(1000);
        $counted_twice = $this->topUp(500, '2026-09-04');
        $this->anExpense(['amount' => 800, 'method' => Expense::METHOD_PETTY_CASH]);

        $this->actingAs($this->finance())
            ->post(route('books.petty-cash.destroy', $counted_twice))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSoftDeleted($counted_twice);
        $this->assertSame(200.0, PettyCashTopup::balance());
    }

    public function test_a_top_up_whose_money_is_already_spent_cannot_be_removed(): void
    {
        $topup = $this->topUp(1000);
        $this->anExpense(['amount' => 800, 'method' => Expense::METHOD_PETTY_CASH]);

        $this->actingAs($this->finance())
            ->post(route('books.petty-cash.destroy', $topup))
            ->assertSessionHasErrors('amount');

        $this->assertNotSoftDeleted($topup);
        $this->assertSame(200.0, PettyCashTopup::balance());
    }

    /* ---------------- the buttons are on the page ---------------- */

    /** Both rows carry both buttons - the whole point is being able to reach them. */
    public function test_the_books_page_offers_edit_and_remove_on_both_lists(): void
    {
        $expense = $this->anExpense(['spent_at' => now()->toDateString()]);
        $topup = $this->topUp(1000, now()->toDateString());

        $html = $this->actingAs($this->finance())->get('/books')->assertOk()->getContent();

        $this->assertStringContainsString(route('books.expenses.edit', $expense), $html);
        $this->assertStringContainsString(route('books.expenses.destroy', $expense), $html);
        $this->assertStringContainsString(route('books.petty-cash.update', $topup), $html);
        $this->assertStringContainsString(route('books.petty-cash.destroy', $topup), $html);
    }

    /** And neither deletes without asking first. */
    public function test_nothing_is_deleted_without_asking(): void
    {
        $this->anExpense(['spent_at' => now()->toDateString()]);
        $this->topUp(1000, now()->toDateString());

        $html = $this->actingAs($this->finance())->get('/books')->assertOk()->getContent();

        $this->assertSame(2, substr_count($html, "confirm('Are you sure?"),
            'a delete button lost its confirmation');
    }

    /* ---------------- who may ---------------- */

    public function test_sales_cannot_edit_the_books(): void
    {
        $expense = $this->anExpense();
        $sales = User::factory()->create(['job_role' => User::ROLE_SALES, 'is_active' => true]);

        $this->actingAs($sales)->get(route('books.expenses.edit', $expense))->assertForbidden();
        $this->actingAs($sales)
            ->post(route('books.expenses.update', $expense), $this->correction(['supplier' => 'Nope']))
            ->assertForbidden();

        $this->assertSame('Divisora Textles', $expense->fresh()->supplier);
    }

    public function test_a_guest_cannot_edit_the_books(): void
    {
        $expense = $this->anExpense();

        $this->get(route('books.expenses.edit', $expense))->assertRedirect(route('login'));
        $this->post(route('books.expenses.update', $expense), $this->correction())->assertRedirect(route('login'));
    }
}
