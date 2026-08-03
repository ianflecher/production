<?php

namespace Tests\Feature;

use App\Models\InventoryItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * Bulk-loading raw materials from a spreadsheet.
 *
 * The file is a CSV of:  name , unit , quantity
 * A header row starting with "name" is optional and skipped.
 */
class InventoryImportTest extends TestCase
{
    use RefreshDatabase;

    private function desk(): User
    {
        return User::factory()->create(['job_role' => User::JOB_SUPPLY_CHAIN, 'is_active' => true]);
    }

    private function csv(string $contents, string $name = 'materials.csv'): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'imp').'.csv';
        file_put_contents($path, $contents);

        return new UploadedFile($path, $name, 'text/csv', null, true);
    }

    public function test_a_plain_three_column_file_loads(): void
    {
        $this->actingAs($this->desk())->post('/inventory-import', [
            'file' => $this->csv("Cotton Fabric,yards,120\nPolyester Thread,cones,40\n"),
        ])->assertRedirect();

        $this->assertSame(2, InventoryItem::count());
        $fabric = InventoryItem::where('name', 'Cotton Fabric')->firstOrFail();
        $this->assertSame('yards', $fabric->unit);
        $this->assertEqualsWithDelta(120.0, (float) $fabric->quantity, 0.01);
    }

    public function test_a_header_row_is_skipped(): void
    {
        $this->actingAs($this->desk())->post('/inventory-import', [
            'file' => $this->csv("name,unit,quantity\nCotton Fabric,yards,120\n"),
        ]);

        $this->assertSame(1, InventoryItem::count());
        $this->assertNull(InventoryItem::where('name', 'name')->first(), 'the header must not become an item');
    }

    public function test_a_file_saved_by_excel_with_a_bom_still_works(): void
    {
        $this->actingAs($this->desk())->post('/inventory-import', [
            'file' => $this->csv("\xEF\xBB\xBFname,unit,quantity\nCotton Fabric,yards,120\n"),
        ]);

        $this->assertNotNull(InventoryItem::where('name', 'Cotton Fabric')->first());
    }

    public function test_the_unit_defaults_to_pcs_when_left_blank(): void
    {
        $this->actingAs($this->desk())->post('/inventory-import', [
            'file' => $this->csv("Zipper,,250\n"),
        ]);

        $this->assertSame('pcs', InventoryItem::where('name', 'Zipper')->value('unit'));
    }

    public function test_importing_again_updates_the_item_rather_than_duplicating_it(): void
    {
        $user = $this->desk();

        $this->actingAs($user)->post('/inventory-import', ['file' => $this->csv("Cotton Fabric,yards,100\n")]);
        $this->actingAs($user)->post('/inventory-import', ['file' => $this->csv("Cotton Fabric,yards,175\n")]);

        $this->assertSame(1, InventoryItem::count(), 'the same material must not be created twice');
        $this->assertEqualsWithDelta(175.0, (float) InventoryItem::first()->quantity, 0.01);
    }

    public function test_the_change_is_recorded_as_a_stock_movement(): void
    {
        $this->actingAs($this->desk())->post('/inventory-import', [
            'file' => $this->csv("Cotton Fabric,yards,120\n"),
        ]);

        // An import is attributable like any other stock change.
        $this->assertDatabaseHas('stock_movements', [
            'inventory_item_id' => InventoryItem::first()->id,
            'note' => 'CSV import',
        ]);
    }

    public function test_rows_missing_a_name_or_quantity_are_skipped_not_fatal(): void
    {
        $this->actingAs($this->desk())->post('/inventory-import', [
            'file' => $this->csv(
                "Cotton Fabric,yards,120\n"
                .",yards,50\n"              // no name
                ."Broken Thread,cones,abc\n" // quantity isn't a number
                ."Zipper,pcs,300\n"
            ),
        ])->assertRedirect();

        // The good rows still land; the bad ones are counted, not thrown.
        $this->assertSame(2, InventoryItem::count());
        $this->assertNotNull(InventoryItem::where('name', 'Zipper')->first());
        $this->assertNull(InventoryItem::where('name', 'Broken Thread')->first());
    }

    public function test_a_non_csv_file_is_refused(): void
    {
        $this->actingAs($this->desk())->post('/inventory-import', [
            'file' => UploadedFile::fake()->image('not-a-spreadsheet.jpg'),
        ])->assertInvalid(['file']);

        $this->assertSame(0, InventoryItem::count());
    }

    public function test_a_file_is_required(): void
    {
        $this->actingAs($this->desk())->post('/inventory-import', [])->assertInvalid(['file']);
    }

    public function test_only_the_raw_materials_desk_can_import(): void
    {
        $artist = User::factory()->create(['job_role' => User::JOB_ARTIST, 'is_active' => true]);

        $this->actingAs($artist)->post('/inventory-import', [
            'file' => $this->csv("Cotton Fabric,yards,120\n"),
        ])->assertForbidden();

        $this->assertSame(0, InventoryItem::count());
    }
}
