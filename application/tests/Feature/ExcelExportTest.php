<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\InventoryItem;
use App\Models\Payment;
use App\Models\ProductionOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

/**
 * The exports are real Excel files, not CSV with a friendly button.
 *
 * CSV opens in Excel, but everything in it is a string: ₱25,500.00 will not
 * sum, a date lands in whatever order the reader guesses, and a reference like
 * 5909978E is helpfully converted to 5.9E+08. A bookkeeper then retypes the
 * lot — the slow way, and the way figures get changed by accident.
 */
class ExcelExportTest extends TestCase
{
    use RefreshDatabase;

    private const XLSX = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    private function ledgerWithOnePayment(bool $vat = true): User
    {
        $sales = User::factory()->create([
            'job_role' => User::ROLE_SALES, 'is_active' => true, 'name' => 'Ysabel',
        ]);
        $client = Client::create(['name' => 'Cecilia', 'last_name' => 'Villanueva', 'tin' => '373-761-261-000']);

        $order = ProductionOrder::create([
            'order_number' => 'IC2026-00074', 'customer_name' => 'Cecilia Villanueva',
            'client_id' => $client->id, 'product_type' => 'round_neck', 'quantity' => 30,
            'total_price' => 28000, 'vat_inclusive' => $vat,
            'due_date' => now()->addWeek(), 'created_by' => $sales->id, 'status' => 'active',
        ]);

        Payment::create([
            'production_order_id' => $order->id,
            'amount' => 25500,
            'kind' => 'downpayment',
            'method' => 'Bank Transfer',
            // Excel turns this into 5.9E+08 unless it is written as text.
            'reference' => '5909978E',
            'paid_at' => now()->subDay(),
            'recorded_by' => $sales->id,
        ]);

        return User::factory()->create(['job_role' => User::ROLE_FINANCE, 'is_active' => true]);
    }

    /** Download an export and open it the way Excel would. */
    private function sheetFrom(User $who, string $url)
    {
        $response = $this->actingAs($who)->get($url)->assertOk()->assertHeader('Content-Type', self::XLSX);

        $path = tempnam(sys_get_temp_dir(), 'xl').'.xlsx';
        file_put_contents($path, $response->streamedContent());

        // A real xlsx is a zip. A CSV renamed would fail right here.
        $this->assertSame('PK', substr(file_get_contents($path), 0, 2), 'not a real xlsx');

        return IOFactory::load($path)->getActiveSheet();
    }

    public function test_money_is_a_number_excel_can_add_up(): void
    {
        $finance = $this->ledgerWithOnePayment();
        $sheet = $this->sheetFrom($finance, '/finance/export');

        // Row 4 is the heading row, so the first payment is row 5.
        $amount = $sheet->getCell('F5');

        $this->assertIsFloat($amount->getValue());
        $this->assertEqualsWithDelta(25500.0, $amount->getValue(), 0.01);
        $this->assertStringContainsString('#,##0.00', $amount->getStyle()->getNumberFormat()->getFormatCode());
    }

    public function test_a_reference_is_not_mangled_into_scientific_notation(): void
    {
        $finance = $this->ledgerWithOnePayment();
        $sheet = $this->sheetFrom($finance, '/finance/export');

        $this->assertSame('5909978E', $sheet->getCell('I5')->getValue(),
            'a reference must survive as typed, not become 5.9E+08');
    }

    public function test_vat_is_broken_out_so_the_line_can_be_checked(): void
    {
        $finance = $this->ledgerWithOnePayment(vat: true);
        $sheet = $this->sheetFrom($finance, '/finance/export');

        $net = (float) $sheet->getCell('D5')->getValue();
        $vat = (float) $sheet->getCell('E5')->getValue();
        $gross = (float) $sheet->getCell('F5')->getValue();

        $this->assertEqualsWithDelta($gross, $net + $vat, 0.02, 'net + VAT must equal what was paid');
        $this->assertGreaterThan(0, $vat);
    }

    public function test_a_non_vat_order_shows_no_vat(): void
    {
        $finance = $this->ledgerWithOnePayment(vat: false);
        $sheet = $this->sheetFrom($finance, '/finance/export');

        $this->assertEqualsWithDelta(0.0, (float) $sheet->getCell('E5')->getValue(), 0.01);
    }

    public function test_the_total_is_a_formula_not_a_typed_number(): void
    {
        // Typed totals stop agreeing the moment anybody filters or deletes a row.
        $finance = $this->ledgerWithOnePayment();
        $sheet = $this->sheetFrom($finance, '/finance/export');

        $last = $sheet->getHighestRow();

        $this->assertSame('TOTAL', $sheet->getCell('A'.$last)->getValue());
        $this->assertStringStartsWith('=SUM(', (string) $sheet->getCell('F'.$last)->getValue());
    }

    public function test_a_date_is_a_date(): void
    {
        $finance = $this->ledgerWithOnePayment();
        $sheet = $this->sheetFrom($finance, '/finance/export');

        $cell = $sheet->getCell('L5');

        $this->assertIsNumeric($cell->getValue(), 'a date must be an Excel date, not text');
        $this->assertStringContainsString('yyyy', $cell->getStyle()->getNumberFormat()->getFormatCode());
    }

    public function test_the_sheet_says_what_it_is_and_when_it_was_taken(): void
    {
        $finance = $this->ledgerWithOnePayment();
        $sheet = $this->sheetFrom($finance, '/finance/export');

        $this->assertSame('Payment ledger', $sheet->getCell('A1')->getValue());
        $this->assertStringContainsString('Exported', (string) $sheet->getCell('A2')->getValue());
        // Headings stay put on a long ledger.
        $this->assertSame('A5', $sheet->getFreezePane());
    }

    public function test_the_stock_export_is_excel_too(): void
    {
        $desk = User::factory()->create(['job_role' => 'Raw materials', 'is_active' => true]);
        InventoryItem::create(['name' => 'Aircool navy', 'unit' => 'pcs', 'quantity' => 0, 'category' => 'COTTON SHIRT']);
        InventoryItem::create(['name' => 'Dri-fit white', 'unit' => 'pcs', 'quantity' => 40, 'category' => 'COTTON SHIRT']);

        $sheet = $this->sheetFrom($desk, '/inventory-export');

        $this->assertSame('Raw materials stock', $sheet->getCell('A1')->getValue());
        $this->assertIsFloat($sheet->getCell('G5')->getValue(), 'quantity must be a number');

        // The reorder list is readable straight off the sheet.
        $statuses = [];
        for ($r = 5; $r <= $sheet->getHighestRow(); $r++) {
            $statuses[] = (string) $sheet->getCell('H'.$r)->getValue();
        }
        $this->assertContains('OUT OF STOCK', $statuses);
    }

    public function test_the_expense_export_is_excel_too(): void
    {
        $finance = User::factory()->create(['job_role' => User::ROLE_FINANCE, 'is_active' => true]);
        \App\Models\Expense::create([
            'category' => 'rent', 'description' => 'Shop rent', 'amount' => 22000,
            'spent_at' => now(), 'method' => 'Bank Transfer', 'recorded_by' => $finance->id,
        ]);

        $sheet = $this->sheetFrom($finance, '/books/export');

        $this->assertIsFloat($sheet->getCell('D5')->getValue());
        $this->assertStringStartsWith('=SUM(', (string) $sheet->getCell('D'.$sheet->getHighestRow())->getValue());
    }

    public function test_an_export_with_nothing_in_it_still_opens(): void
    {
        // An empty month is a normal thing to ask for, and a file that will not
        // open is a worse answer than an empty sheet.
        $finance = User::factory()->create(['job_role' => User::ROLE_FINANCE, 'is_active' => true]);

        $sheet = $this->sheetFrom($finance, '/books/export?month='.now()->subYears(3)->format('Y-m'));

        $this->assertStringContainsString('Expenses', (string) $sheet->getCell('A1')->getValue());
    }
}
