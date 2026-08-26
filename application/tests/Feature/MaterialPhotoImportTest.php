<?php

namespace Tests\Feature;

use App\Models\InventoryItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/**
 * The mock-up photos come out of the workbook, not the CSV.
 *
 * They float above the grid rather than living in a cell, so a CSV export drops
 * them and the MOCK UP column arrives empty. In the .xlsx they survive, pinned
 * to a cell — and that pin is the only thing tying a picture to a material.
 */
class MaterialPhotoImportTest extends TestCase
{
    use RefreshDatabase;

    /** A stock sheet with a picture pinned over the first row of a block. */
    private function sheetWithPhoto(string $pinnedAt = 'B3'): string
    {
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet();

        $sheet->fromArray([
            ['', 'MOCK UP', 'TYPE OF FABRIC', 'DESCRIPTION', 'BEG BAL', 'REMAINING'],
            ['', '', '', '', 0, 0],
            ['', '', 'COTTON SHIRT', 'AIIZ SHIRT RN BLACK - XS', 13, 10],
            ['', '', '', 'AIIZ SHIRT RN BLACK - S', 8, 0],
            ['', '', '', 'AIIZ SHIRT RN BLACK - M', 5, 20],
        ], null, 'A1');

        $png = tempnam(sys_get_temp_dir(), 'img').'.png';
        $im = imagecreatetruecolor(12, 12);
        imagefill($im, 0, 0, imagecolorallocate($im, 200, 30, 40));
        imagepng($im, $png);

        $drawing = new Drawing;
        $drawing->setPath($png);
        $drawing->setCoordinates($pinnedAt);
        $drawing->setWorksheet($sheet);

        $path = tempnam(sys_get_temp_dir(), 'stock').'.xlsx';
        (new Xlsx($book))->save($path);

        return $path;
    }

    private function materials(): void
    {
        foreach (['XS', 'S', 'M'] as $size) {
            InventoryItem::create(['name' => 'AIIZ SHIRT RN BLACK - '.$size, 'unit' => 'pcs', 'quantity' => 1]);
        }
    }

    public function test_a_pinned_photo_lands_on_the_material_it_is_pinned_to(): void
    {
        Storage::fake('public');
        $this->materials();

        $this->artisan('inventory:photos', ['file' => $this->sheetWithPhoto()])
            ->assertSuccessful();

        $xs = InventoryItem::where('name', 'AIIZ SHIRT RN BLACK - XS')->firstOrFail();

        $this->assertNotNull($xs->photo, 'the pinned row should have got the photo');
        Storage::disk('public')->assertExists($xs->photo);

        // Without --spread only the pinned row is touched.
        $this->assertNull(InventoryItem::where('name', 'AIIZ SHIRT RN BLACK - S')->value('photo'));
    }

    public function test_spread_puts_one_photo_on_every_size_in_the_block(): void
    {
        Storage::fake('public');
        $this->materials();

        $this->artisan('inventory:photos', ['file' => $this->sheetWithPhoto(), '--spread' => true])
            ->assertSuccessful();

        $photos = InventoryItem::pluck('photo', 'name');

        $this->assertCount(3, $photos->filter(), 'all three sizes should be photographed');

        // One picture, shared — not three copies of the same bytes.
        $this->assertCount(1, $photos->filter()->unique(),
            'the block should share a single stored file');
    }

    public function test_a_dry_run_writes_nothing(): void
    {
        Storage::fake('public');
        $this->materials();

        $this->artisan('inventory:photos', ['file' => $this->sheetWithPhoto(), '--dry-run' => true])
            ->assertSuccessful();

        $this->assertNull(InventoryItem::where('name', 'AIIZ SHIRT RN BLACK - XS')->value('photo'));
        $this->assertEmpty(Storage::disk('public')->allFiles());
    }

    public function test_it_refuses_the_csv_and_says_why(): void
    {
        $csv = tempnam(sys_get_temp_dir(), 'stock').'.csv';
        file_put_contents($csv, "MOCK UP,DESCRIPTION\n,AIIZ SHIRT RN BLACK - XS\n");

        $this->artisan('inventory:photos', ['file' => $csv])
            ->expectsOutputToContain('cannot hold a picture')
            ->assertFailed();
    }

    public function test_an_existing_photo_is_kept_unless_forced(): void
    {
        Storage::fake('public');
        $this->materials();

        InventoryItem::where('name', 'AIIZ SHIRT RN BLACK - XS')->update(['photo' => 'inventory-photos/mine.png']);

        $this->artisan('inventory:photos', ['file' => $this->sheetWithPhoto()])->assertSuccessful();
        $this->assertSame('inventory-photos/mine.png',
            InventoryItem::where('name', 'AIIZ SHIRT RN BLACK - XS')->value('photo'));

        $this->artisan('inventory:photos', ['file' => $this->sheetWithPhoto(), '--force' => true])->assertSuccessful();
        $this->assertNotSame('inventory-photos/mine.png',
            InventoryItem::where('name', 'AIIZ SHIRT RN BLACK - XS')->value('photo'));
    }
}
