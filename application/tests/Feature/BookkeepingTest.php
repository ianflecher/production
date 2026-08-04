<?php

namespace Tests\Feature;

use App\Models\Expense;
use App\Models\Payment;
use App\Models\ProductionOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** The finance desk's books: expenses, and income vs expenses vs profit. */
class BookkeepingTest extends TestCase
{
    use RefreshDatabase;

    private function finance(): User
    {
        return User::factory()->create(['job_role' => User::ROLE_FINANCE, 'is_active' => true]);
    }

    private function expensePayload(array $o = []): array
    {
        return array_merge([
            'category' => 'raw_materials',
            'description' => '20 yards cotton fabric',
            'amount' => 1500.50,
            'spent_at' => now()->toDateString(),
            'method' => 'Cash',
        ], $o);
    }

    // ---- Recording ---------------------------------------------------------

    public function test_finance_can_record_an_expense(): void
    {
        $user = $this->finance();

        $this->actingAs($user)->post('/books/expenses', $this->expensePayload())->assertRedirect();

        $this->assertDatabaseHas('expenses', [
            'category' => 'raw_materials',
            'description' => '20 yards cotton fabric',
            'recorded_by' => $user->id,
        ]);
        $this->assertEqualsWithDelta(1500.50, (float) Expense::first()->amount, 0.01);
    }

    public function test_expense_requires_its_core_fields(): void
    {
        $this->actingAs($this->finance())->post('/books/expenses', [])
            ->assertInvalid(['category', 'description', 'amount', 'spent_at']);

        $this->assertSame(0, Expense::count());
    }

    public function test_expense_category_must_be_a_known_one(): void
    {
        $this->actingAs($this->finance())
            ->post('/books/expenses', $this->expensePayload(['category' => 'lamborghini']))
            ->assertInvalid(['category']);
    }

    public function test_expense_amount_must_be_positive(): void
    {
        $this->actingAs($this->finance())
            ->post('/books/expenses', $this->expensePayload(['amount' => 0]))
            ->assertInvalid(['amount']);
    }

    public function test_a_receipt_can_be_attached_and_is_kept_private(): void
    {
        Storage::fake('local');

        $this->actingAs($this->finance())->post('/books/expenses', $this->expensePayload([
            'receipt' => UploadedFile::fake()->image('receipt.jpg'),
        ]));

        $expense = Expense::firstOrFail();
        $this->assertTrue($expense->hasReceipt());
        Storage::disk('local')->assertExists($expense->receipt_path);
    }

    public function test_removing_an_expense_takes_it_out_of_the_books(): void
    {
        $user = $this->finance();
        $this->actingAs($user)->post('/books/expenses', $this->expensePayload());
        $expense = Expense::firstOrFail();

        $this->actingAs($user)->post("/books/expenses/{$expense->id}/delete")->assertRedirect();

        $this->assertSoftDeleted('expenses', ['id' => $expense->id]);
        $this->assertSame(0, Expense::count());
    }

    // ---- The profit picture ------------------------------------------------

    public function test_the_books_show_income_minus_expenses(): void
    {
        $user = $this->finance();

        // Money in: a payment on an order this month.
        $sales = User::factory()->create(['job_role' => User::ROLE_SALES, 'is_active' => true]);
        $this->actingAs($sales)->post('/orders', [
            'order_number' => 'IC2026-08080',
            'client_name' => 'Books Co',
            'client_last_name' => 'Cruz',
            'client_contact' => '0917-000-0000',
            'client_office_address' => 'Angeles City',
            'client_delivery_address' => 'Angeles City',
            'due_date' => now()->addWeeks(3)->toDateString(),
            'product_type' => 'round_neck',
            'sizes' => ['M' => 10],
        ]);
        $order = ProductionOrder::where('order_number', 'IC2026-08080')->firstOrFail();
        Payment::create([
            'production_order_id' => $order->id,
            'amount' => 10000,
            'method' => 'GCash',
            'kind' => 'downpayment',
            'paid_at' => now(),
            'recorded_by' => $sales->id,
        ]);

        // Money out.
        $this->actingAs($user)->post('/books/expenses', $this->expensePayload(['amount' => 2500]));

        $response = $this->actingAs($user)->get('/books')->assertOk();
        $response->assertViewHas('income', 10000.0);
        $response->assertViewHas('expenseTotal', 2500.0);
        $response->assertViewHas('profit', 7500.0);
    }

    public function test_a_month_with_more_out_than_in_reads_as_a_loss(): void
    {
        $user = $this->finance();
        $this->actingAs($user)->post('/books/expenses', $this->expensePayload(['amount' => 5000]));

        $this->actingAs($user)->get('/books')->assertOk()->assertViewHas('profit', -5000.0);
    }

    public function test_expenses_from_another_month_are_not_counted(): void
    {
        $user = $this->finance();

        $this->actingAs($user)->post('/books/expenses', $this->expensePayload([
            'amount' => 999,
            'spent_at' => now()->subMonths(2)->toDateString(),
        ]));

        // Current month should be empty…
        $this->actingAs($user)->get('/books')->assertOk()->assertViewHas('expenseTotal', 0.0);

        // …but showing that month should find it.
        $this->actingAs($user)
            ->get('/books?month='.now()->subMonths(2)->format('Y-m'))
            ->assertOk()->assertViewHas('expenseTotal', 999.0);
    }

    public function test_export_returns_a_csv(): void
    {
        $user = $this->finance();
        $this->actingAs($user)->post('/books/expenses', $this->expensePayload());

        $this->actingAs($user)->get('/books/export')
            ->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
    }

    // ---- Access ------------------------------------------------------------

    public function test_leader_can_see_the_books(): void
    {
        $leader = User::factory()->create(['job_role' => User::ROLE_LEADER, 'is_active' => true]);
        $this->actingAs($leader)->get('/books')->assertOk();
    }

    public function test_sales_cannot_see_or_record_in_the_books(): void
    {
        $sales = User::factory()->create(['job_role' => User::ROLE_SALES, 'is_active' => true]);

        $this->actingAs($sales)->get('/books')->assertForbidden();
        $this->actingAs($sales)->post('/books/expenses', $this->expensePayload())->assertForbidden();
        $this->assertSame(0, Expense::count());
    }

    public function test_an_agent_cannot_see_the_books(): void
    {
        $agent = User::factory()->create(['job_role' => 'sewing', 'is_active' => true]);
        $this->actingAs($agent)->get('/books')->assertForbidden();
    }

    public function test_a_guest_cannot_see_the_books(): void
    {
        $this->get('/books')->assertRedirect('/login');
    }
}
