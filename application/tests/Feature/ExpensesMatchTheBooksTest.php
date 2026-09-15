<?php

namespace Tests\Feature;

use App\Models\Expense;
use App\Models\PettyCashTopup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

/**
 * An expense carries what the books actually need.
 *
 * What was recorded was a date, one of ten homemade buckets, a description,
 * an amount and a method. What the bookkeeper works from is a different sheet
 * entirely - who ordered it, when it was ordered and when it was paid, the
 * invoice number, the supplier with their TIN and address, which of
 * seventy-eight account titles it belongs to, and whether it was VAT.
 *
 * All of that was being kept on paper beside the system, and the month was
 * rearranged by hand every time before it could go to the bookkeeper.
 */
class ExpensesMatchTheBooksTest extends TestCase
{
    use RefreshDatabase;

    private function finance(): User
    {
        return User::factory()->create(['job_role' => 'finance', 'is_active' => true]);
    }

    /** @return array<string, mixed> */
    private function validExpense(array $extra = []): array
    {
        return array_merge([
            'account_title' => 'COS - Fabric',
            'description' => '20 yards cotton from Divisoria',
            'amount' => 4500,
            'spent_at' => '2026-09-03',
            'receipt' => UploadedFile::fake()->create('or.pdf', 20, 'application/pdf'),
        ], $extra);
    }

    /* ---------------- the chart of accounts ---------------- */

    /** Seventy-eight titles in four groups, exactly as the bookkeeper keeps them. */
    public function test_the_shops_own_account_titles_are_offered(): void
    {
        $this->assertCount(78, Expense::accountTitles());

        $this->assertSame([
            'Cost of Sales (COS)',
            'Employee Related Expenses (ERE)',
            'Admin Expenses (AE)',
            'Non-Employee Related Expenses (NERE)',
        ], array_keys(Expense::ACCOUNT_TITLES));

        $this->assertContains('COS - Sublimation Paper', Expense::accountTitles());
        $this->assertContains('AE - BIR (Form 2550Q - VAT)', Expense::accountTitles());
        $this->assertContains('Transportation & Travel Allowance', Expense::accountTitles());
    }

    public function test_a_title_knows_which_group_it_belongs_to(): void
    {
        $this->assertSame('Cost of Sales (COS)', Expense::groupOf('COS - Ink'));
        $this->assertSame('Employee Related Expenses (ERE)', Expense::groupOf('ERE - SSS'));
        $this->assertSame('Non-Employee Related Expenses (NERE)', Expense::groupOf('Fuel and Oil'));
        $this->assertNull(Expense::groupOf('Something invented'));
    }

    /** The old homemade buckets are gone, not merely hidden. */
    public function test_a_made_up_account_title_is_refused(): void
    {
        $this->actingAs($this->finance())
            ->post(route('books.expenses.store'), $this->validExpense(['account_title' => 'raw_materials']))
            ->assertSessionHasErrors('account_title');

        $this->assertSame(0, Expense::count());
    }

    /* ---------------- what a row now holds ---------------- */

    public function test_an_expense_keeps_everything_the_books_ask_for(): void
    {
        Storage::fake('local');

        $this->actingAs($this->finance())
            ->post(route('books.expenses.store'), $this->validExpense([
                'ordered_by' => 'Maam Khaye',
                'paid_at' => '2026-09-10',
                'reference_type' => 'PO',
                'reference' => '0042',
                'si_cr_no' => 'SI-99817',
                'supplier' => 'Divisoria Textiles',
                'tin' => '123-456-789-000',
                'business_address' => '168 Mall, Divisoria, Manila',
                'vat_status' => 'VAT',
                'method' => 'Bank Tranfer (AUB)',
            ]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $e = Expense::firstOrFail();

        $this->assertSame('Maam Khaye', $e->ordered_by);
        $this->assertSame('2026-09-10', $e->paid_at->toDateString());
        $this->assertSame('PO', $e->reference_type);
        $this->assertSame('SI-99817', $e->si_cr_no);
        $this->assertSame('Divisoria Textiles', $e->supplier);
        $this->assertSame('123-456-789-000', $e->tin);
        $this->assertSame('VAT', $e->vat_status);
        $this->assertSame('COS - Fabric', $e->account_title);
    }

    /** Ordered in September, paid in October. Never the other way round. */
    public function test_it_cannot_have_been_paid_before_it_was_ordered(): void
    {
        $this->actingAs($this->finance())
            ->post(route('books.expenses.store'), $this->validExpense([
                'spent_at' => '2026-09-10',
                'paid_at' => '2026-09-03',
            ]))
            ->assertSessionHasErrors('paid_at');
    }

    /** Most of it is optional: a coffee run has no TIN and no invoice. */
    public function test_the_small_things_need_only_the_three_that_matter(): void
    {
        Storage::fake('local');

        $this->actingAs($this->finance())
            ->post(route('books.expenses.store'), $this->validExpense([
                'account_title' => 'Other Expense',
                'description' => 'Coffee for the floor',
                'amount' => 250,
            ]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame(1, Expense::count());
    }

    /* ---------------- the payment methods ---------------- */

    /**
     * The shop's own list. "GCash" alone matches two phones, and the
     * bookkeeper reconciles each against a different statement.
     */
    public function test_the_payment_methods_name_the_actual_account(): void
    {
        $this->assertSame([
            'Cash (Petty Cash Fund)',
            'GCash (iPhone)',
            'GCash (Purchasing Phone)',
            'Bank Tranfer (AUB)',
            'Bank Tranfer (EWB)',
            'Bank Tranfer (UB)',
            'Others',
        ], Expense::METHODS);
    }

    /**
     * The one that would have gone wrong quietly: the tin's balance guard
     * matches on this exact string, and renaming the method without it would
     * have left the guard never firing and the tin reading as untouched.
     */
    public function test_the_petty_cash_tin_is_still_the_one_in_the_list(): void
    {
        $this->assertContains(Expense::METHOD_PETTY_CASH, Expense::METHODS);
    }

    public function test_the_tin_still_refuses_to_pay_out_more_than_it_holds(): void
    {
        Storage::fake('local');
        $finance = $this->finance();

        PettyCashTopup::create(['amount' => 1000, 'occurred_at' => '2026-09-01', 'recorded_by' => $finance->id]);

        $this->actingAs($finance)
            ->post(route('books.expenses.store'), $this->validExpense([
                'amount' => 5000,
                'method' => Expense::METHOD_PETTY_CASH,
            ]))
            ->assertSessionHasErrors('amount');

        $this->assertSame(0, Expense::count());
    }

    /* ---------------- finding one again ---------------- */

    private function seedThree(User $finance): void
    {
        Expense::create(['account_title' => 'COS - Fabric', 'description' => 'Cotton', 'amount' => 100,
            'spent_at' => '2026-09-02', 'supplier' => 'Divisoria Textiles', 'recorded_by' => $finance->id]);
        Expense::create(['account_title' => 'Fuel and Oil', 'description' => 'Diesel', 'amount' => 200,
            'spent_at' => '2026-09-03', 'ordered_by' => 'Sir Boying', 'si_cr_no' => 'SI-4412', 'recorded_by' => $finance->id]);
        Expense::create(['account_title' => 'Office Supplies Expense', 'description' => 'Bond paper', 'amount' => 300,
            'spent_at' => '2026-09-04', 'recorded_by' => $finance->id]);
    }

    /** By what it was for. */
    public function test_expenses_can_be_searched_by_description(): void
    {
        $finance = $this->finance();
        $this->seedThree($finance);

        $this->actingAs($finance)->get(route('books.index', ['month' => '2026-09', 'q' => 'diesel']))
            ->assertOk()
            ->assertSee('Diesel')
            ->assertDontSee('Bond paper');
    }

    /**
     * And by everything else the bookkeeper might remember instead - "find
     * the Divisoria one" is how the question actually arrives.
     */
    public function test_expenses_can_be_searched_by_supplier_title_or_invoice(): void
    {
        $finance = $this->finance();
        $this->seedThree($finance);

        foreach (['Divisoria' => 'Cotton', 'Fuel and Oil' => 'Diesel', 'SI-4412' => 'Diesel', 'Boying' => 'Diesel'] as $needle => $expected) {
            $this->actingAs($finance)->get(route('books.index', ['month' => '2026-09', 'q' => $needle]))
                ->assertOk()
                ->assertSee($expected);
        }
    }

    public function test_an_empty_search_shows_the_whole_month(): void
    {
        $finance = $this->finance();
        $this->seedThree($finance);

        $this->actingAs($finance)->get(route('books.index', ['month' => '2026-09']))
            ->assertOk()
            ->assertSee('Cotton')
            ->assertSee('Diesel')
            ->assertSee('Bond paper');
    }

    /* ---------------- the reference and its type ---------------- */

    /**
     * The form shows the type inside the reference box, so what arrives is
     * "PO-0042". The two are stored apart - the type in its own column so it
     * can be sorted and counted - and without stripping the prefix back off
     * the export would join the type on a second time and read PO-PO-0042.
     */
    public function test_a_reference_sent_with_its_prefix_is_stored_once(): void
    {
        Storage::fake('local');

        $this->actingAs($this->finance())
            ->post(route('books.expenses.store'), $this->validExpense([
                'reference_type' => 'PO',
                'reference' => 'PO-0042',
            ]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $e = Expense::firstOrFail();

        $this->assertSame('PO', $e->reference_type);
        $this->assertSame('0042', $e->reference);
    }

    /** Typed lower case by somebody in a hurry. */
    public function test_the_prefix_is_stripped_whatever_its_case(): void
    {
        Storage::fake('local');

        $this->actingAs($this->finance())
            ->post(route('books.expenses.store'), $this->validExpense([
                'reference_type' => 'RFP',
                'reference' => 'rfp-0008',
            ]))
            ->assertRedirect();

        $this->assertSame('0008', Expense::firstOrFail()->reference);
    }

    /** And a bare number, from somebody whose script never ran, is left alone. */
    public function test_a_bare_reference_number_is_kept_as_it_is(): void
    {
        Storage::fake('local');

        $this->actingAs($this->finance())
            ->post(route('books.expenses.store'), $this->validExpense([
                'reference_type' => 'PCF',
                'reference' => '0011',
            ]))
            ->assertRedirect();

        $this->assertSame('0011', Expense::firstOrFail()->reference);
    }

    /** A type with no number behind it is not a reference. */
    public function test_a_prefix_on_its_own_is_not_a_reference(): void
    {
        Storage::fake('local');

        $this->actingAs($this->finance())
            ->post(route('books.expenses.store'), $this->validExpense([
                'reference_type' => 'PO',
                'reference' => 'PO-',
            ]))
            ->assertRedirect();

        $e = Expense::firstOrFail();

        $this->assertSame('PO', $e->reference_type);
        $this->assertNull($e->reference);
    }

    /** A reference that happens to start with another type is not mangled. */
    public function test_only_its_own_prefix_is_taken_off(): void
    {
        Storage::fake('local');

        $this->actingAs($this->finance())
            ->post(route('books.expenses.store'), $this->validExpense([
                'reference_type' => 'PO',
                'reference' => 'RFP-0008',
            ]))
            ->assertRedirect();

        $this->assertSame('RFP-0008', Expense::firstOrFail()->reference);
    }

    /* ---------------- the export ---------------- */

    /** The bookkeeper's own columns, in the bookkeeper's own order. */
    public function test_the_export_is_in_the_bookkeepers_column_order(): void
    {
        $finance = $this->finance();
        $this->seedThree($finance);

        $path = tempnam(sys_get_temp_dir(), 'exp').'.xlsx';
        file_put_contents($path, $this->actingAs($finance)
            ->get(route('books.export', ['month' => '2026-09']))
            ->assertOk()
            ->streamedContent());

        $sheet = IOFactory::load($path)->getActiveSheet();
        $header = [];

        // The header sits below the title and subtitle rows.
        foreach ($sheet->rangeToArray('A4:N4')[0] as $cell) {
            $header[] = trim((string) $cell);
        }

        $this->assertSame([
            'Order Date', 'Ordered By', 'Reference', 'Date Paid', 'SI/CR No.',
            'TIN', 'Busines Address', 'Supplier/Vendor', 'Description',
            'Account Titles', 'Amount', 'Payment Method', 'VAT/N-VAT', 'Recorded by',
        ], $header);

        @unlink($path);
    }

    /** The two halves of a reference read as one thing on the sheet. */
    public function test_the_reference_comes_out_joined(): void
    {
        $finance = $this->finance();

        Expense::create(['account_title' => 'COS - Ink', 'description' => 'Ink', 'amount' => 100,
            'spent_at' => '2026-09-02', 'reference_type' => 'PO', 'reference' => '0042',
            'recorded_by' => $finance->id]);

        $path = tempnam(sys_get_temp_dir(), 'exp').'.xlsx';
        file_put_contents($path, $this->actingAs($finance)
            ->get(route('books.export', ['month' => '2026-09']))->streamedContent());

        $sheet = IOFactory::load($path)->getActiveSheet();

        $this->assertSame('PO-0042', trim((string) $sheet->getCell('C5')->getValue()));

        @unlink($path);
    }

    /** What is on the screen is what comes out of the file. */
    public function test_the_export_carries_the_search_through(): void
    {
        $finance = $this->finance();
        $this->seedThree($finance);

        $path = tempnam(sys_get_temp_dir(), 'exp').'.xlsx';
        file_put_contents($path, $this->actingAs($finance)
            ->get(route('books.export', ['month' => '2026-09', 'q' => 'Divisoria']))->streamedContent());

        $sheet = IOFactory::load($path)->getActiveSheet();
        $body = $sheet->toArray();

        $flat = implode(' ', array_map(fn ($r) => implode(' ', array_map('strval', $r)), $body));

        $this->assertStringContainsString('Cotton', $flat);
        $this->assertStringNotContainsString('Bond paper', $flat);

        @unlink($path);
    }
}
