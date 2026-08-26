<?php

namespace Tests\Feature;

use App\Models\InventoryItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/**
 * The stock sheet arrives as a workbook, because that is what the shop keeps
 * it in. Asking for it as CSV first was a step that had to be remembered every
 * time, and forgetting it produced a validation error rather than an import.
 */
class InventoryExcelImportTest extends TestCase
{
    use RefreshDatabase;

    private function desk(): User
    {
        return User::factory()->create(['job_role' => 'Raw Materials', 'is_active' => true]);
    }

    /** Write rows to a real .xlsx and hand it over as an upload. */
    private function workbook(array $rows, string $name = 'stock.xlsx'): UploadedFile
    {
        $book = new Spreadsheet;
        $book->getActiveSheet()->fromArray($rows, null, 'A1');

        $path = tempnam(sys_get_temp_dir(), 'xls').'.xlsx';
        (new Xlsx($book))->save($path);

        return new UploadedFile($path, $name, null, null, true);
    }

    public function test_a_plain_workbook_imports(): void
    {
        $this->actingAs($this->desk())
            ->post(route('inventory.import'), ['file' => $this->workbook([
                ['Name', 'Unit', 'Quantity'],
                ['Cotton Fabric', 'yards', 120],
                ['Sublimation Paper', 'rolls', 8],
            ])])
            ->assertSessionHasNoErrors();

        $this->assertSame(2, InventoryItem::count());
        $this->assertSame(120.0, (float) InventoryItem::where('name', 'Cotton Fabric')->value('quantity'));
        $this->assertSame('rolls', InventoryItem::where('name', 'Sublimation Paper')->value('unit'));
    }

    public function test_the_shops_stock_sheet_layout_is_read_from_a_workbook_too(): void
    {
        // The wide layout the shop actually exports — the same one the CSV
        // path already recognised.
        $this->actingAs($this->desk())
            ->post(route('inventory.import'), ['file' => $this->workbook([
                ['RAW MATERIALS STOCKS'],
                ['TYPE OF FABRIC', 'DESCRIPTION', 'BEG BAL', 'RECEIVED', 'LESS', 'REMAINING', 'NOTES'],
                ['DRIFIT', 'Drifit White', 50, 20, 10, 60, 'reorder soon'],
                ['', 'Drifit Black', 30, 0, 5, 25, ''],
            ])])
            ->assertSessionHasNoErrors();

        $this->assertSame(2, InventoryItem::count());
        $this->assertSame(60.0, (float) InventoryItem::where('name', 'Drifit White')->value('quantity'));
        $this->assertSame(25.0, (float) InventoryItem::where('name', 'Drifit Black')->value('quantity'));
    }

    public function test_blank_spacer_rows_do_not_become_materials(): void
    {
        $this->actingAs($this->desk())
            ->post(route('inventory.import'), ['file' => $this->workbook([
                ['Name', 'Unit', 'Quantity'],
                ['Thread', 'cones', 40],
                ['', '', ''],
                [null, null, null],
                ['Zipper', 'pcs', 200],
            ])])
            ->assertSessionHasNoErrors();

        $this->assertSame(2, InventoryItem::count());
    }

    public function test_csv_still_imports(): void
    {
        $csv = "Name,Unit,Quantity\nCanvas,yards,15\n";

        $this->actingAs($this->desk())
            ->post(route('inventory.import'), [
                'file' => UploadedFile::fake()->createWithContent('stock.csv', $csv),
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(15.0, (float) InventoryItem::where('name', 'Canvas')->value('quantity'));
    }

    public function test_a_truncated_workbook_says_so_instead_of_500ing(): void
    {
        // What a file copied while Excel still had it open looks like: a zip
        // that starts correctly and stops mid-stream.
        $broken = UploadedFile::fake()->createWithContent(
            'stock.xlsx',
            "PK\x03\x04".str_repeat("\x00", 200),
        );

        $this->actingAs($this->desk())
            ->post(route('inventory.import'), ['file' => $broken])
            ->assertSessionHasErrors('file');

        $this->assertSame(0, InventoryItem::count());
    }
}
