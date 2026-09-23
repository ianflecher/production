<?php

namespace Tests\Feature;

use App\Models\InventoryItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * A stock sheet is a count of what is there, not a delivery note.
 *
 * The caps and the sewing/QC materials are kept in the shop's own spreadsheets
 * and come to us as printed sheets. Importing one twice — because somebody
 * re-ran it, or because the sheet was corrected — must leave the shelf saying
 * what the sheet says, not twice what the sheet says.
 *
 * And it must never reach across to the fabric shelf, which is the raw
 * materials supervisor's and has a command of its own.
 */
class ReadyMadeStockIsCountedNotAddedUpTest extends TestCase
{
    use RefreshDatabase;

    private function sheet(string $csv): string
    {
        $path = tempnam(sys_get_temp_dir(), 'stock').'.csv';
        file_put_contents($path, $csv);

        return $path;
    }

    private const CAPS = <<<'CSV'
    name,category,quantity
    YUPOONG SNAPBACK CAP RED/GREEN,YUPOONG SNAPBACK CAP,64
    ALL BLACK CURVE TRUCKER CAP,MESH/TRUCKER CAP,150
    WHITE/BLUE/RED CURVE TRUCKER CAP,MESH/TRUCKER CAP,0
    CSV;

    public function test_a_sheet_lands_on_the_ready_made_shelf(): void
    {
        $this->artisan('stock:import', ['file' => $this->sheet(self::CAPS)])
            ->assertSuccessful();

        $this->assertSame(3, InventoryItem::where('kind', InventoryItem::KIND_READY_MADE)->count());

        $cap = InventoryItem::where('name', 'YUPOONG SNAPBACK CAP RED/GREEN')->firstOrFail();

        $this->assertSame('64.00', $cap->quantity);
        $this->assertSame('YUPOONG SNAPBACK CAP', $cap->category);
        $this->assertSame('pcs', $cap->unit);
        $this->assertSame(InventoryItem::KIND_READY_MADE, $cap->kind);
    }

    /** Run it twice and the shelf still says what the sheet says. */
    public function test_importing_the_same_sheet_twice_does_not_double_the_shelf(): void
    {
        $path = $this->sheet(self::CAPS);

        $this->artisan('stock:import', ['file' => $path])->assertSuccessful();
        $this->artisan('stock:import', ['file' => $path])->assertSuccessful();

        $this->assertSame(3, InventoryItem::count(), 'the sheet was added a second time');
        $this->assertSame('64.00',
            InventoryItem::where('name', 'YUPOONG SNAPBACK CAP RED/GREEN')->value('quantity'));
    }

    /** A corrected sheet recounts the shelf rather than adding to it. */
    public function test_a_corrected_sheet_recounts_what_is_there(): void
    {
        $this->artisan('stock:import', ['file' => $this->sheet(self::CAPS)])->assertSuccessful();

        $corrected = $this->sheet("name,category,quantity\nYUPOONG SNAPBACK CAP RED/GREEN,YUPOONG SNAPBACK CAP,12\n");

        $this->artisan('stock:import', ['file' => $corrected])->assertSuccessful();

        $this->assertSame('12.00',
            InventoryItem::where('name', 'YUPOONG SNAPBACK CAP RED/GREEN')->value('quantity'));
    }

    /**
     * The supervisor's fabric is not this command's business.
     *
     * A material name is unique across BOTH shelves, so the same name on the
     * fabric shelf is the same material — moving it here would take it off
     * hers. It is left where it is and named in the output.
     */
    public function test_a_name_already_on_the_fabric_shelf_is_left_alone(): void
    {
        $fabric = InventoryItem::create([
            'name' => 'QUIANA (140GSM) BLK', 'category' => 'FABRIC', 'unit' => 'KG',
            'kind' => InventoryItem::KIND_FABRIC, 'quantity' => 46,
        ]);

        // Same name on the other shelf: it must not be mistaken for this one.
        $this->artisan('stock:import', [
            'file' => $this->sheet("name,category,quantity\nQUIANA (140GSM) BLK,CAPS,999\n"),
        ])->assertSuccessful();

        $this->assertSame('46.00', $fabric->fresh()->quantity, 'her fabric was recounted as caps');
        $this->assertSame(InventoryItem::KIND_FABRIC, $fabric->fresh()->kind);
        $this->assertSame(0, InventoryItem::where('kind', InventoryItem::KIND_READY_MADE)->count());
    }

    /* ---------------- and the sheet's picture of it ---------------- */

    /**
     * The shop's sheet has a photograph of every cap and every zip, and the
     * inventory page has somewhere to show it. A keeper asked to tell
     * "ORDINARY SNAPBACK CAP ALL BLACK/GRAY BUTTON" apart from eleven
     * near-identical names answers that in one look.
     */
    public function test_the_sheets_picture_goes_on_the_material(): void
    {
        Storage::fake('public');

        $picture = tempnam(sys_get_temp_dir(), 'cap').'.jpg';
        file_put_contents($picture, 'not really a jpeg, but it is bytes');

        $this->artisan('stock:import', [
            'file' => $this->sheet("name,category,quantity,photo_file\nA CAP,CAPS,4,{$picture}\n"),
        ])->assertSuccessful();

        $item = InventoryItem::where('name', 'A CAP')->firstOrFail();

        $this->assertNotNull($item->photo);
        $this->assertStringStartsWith('inventory-photos/', $item->photo);
        Storage::disk('public')->assertExists($item->photo);
    }

    /** A picture somebody uploaded by hand outlives one off a printout. */
    public function test_a_picture_already_there_is_left_alone(): void
    {
        Storage::fake('public');

        $item = InventoryItem::create([
            'name' => 'A CAP', 'category' => 'CAPS', 'unit' => 'pcs',
            'kind' => InventoryItem::KIND_READY_MADE, 'quantity' => 1,
            'photo' => 'inventory-photos/by-hand.jpg',
        ]);

        $picture = tempnam(sys_get_temp_dir(), 'cap').'.jpg';
        file_put_contents($picture, 'bytes');

        $this->artisan('stock:import', [
            'file' => $this->sheet("name,category,quantity,photo_file\nA CAP,CAPS,4,{$picture}\n"),
        ])->assertSuccessful();

        $this->assertSame('inventory-photos/by-hand.jpg', $item->fresh()->photo);
        $this->assertSame('4.00', $item->fresh()->quantity, 'the count should still have been taken');
    }

    /** A sheet with no pictures in it reads exactly the same. */
    public function test_a_sheet_without_pictures_still_imports(): void
    {
        $this->artisan('stock:import', ['file' => $this->sheet(self::CAPS)])->assertSuccessful();

        $this->assertSame(3, InventoryItem::count());
        $this->assertNull(InventoryItem::first()->photo);
    }

    /** A dry run reads the sheet and changes nothing. */
    public function test_a_dry_run_changes_nothing(): void
    {
        $this->artisan('stock:import', ['file' => $this->sheet(self::CAPS), '--dry' => true])
            ->assertSuccessful();

        $this->assertSame(0, InventoryItem::count());
    }

    /** A row with no name, or no number against it, is not a material. */
    public function test_rows_that_say_nothing_are_skipped(): void
    {
        $this->artisan('stock:import', [
            'file' => $this->sheet("name,category,quantity\n,CAPS,5\nA REAL CAP,CAPS,7\nNO COUNT,CAPS,\n"),
        ])->assertSuccessful();

        $this->assertSame(1, InventoryItem::count());
        $this->assertSame('A REAL CAP', InventoryItem::first()->name);
    }
}
