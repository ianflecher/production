<?php

namespace Tests\Feature;

use App\Models\InventoryItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * Loading the shop's own RAW MATERIALS STOCKS export, which is wider than the
 * plain name/unit/quantity file:
 *
 *   , MOCK UP, TYPE OF FABRIC, DESCRIPTION, BEG BAL, RECEIVED, TOTAL, LESS,
 *     REMAINING, REMARKS, DATE, NOTES
 *
 * The group is written once per block and inherited by the rows under it.
 */
class StockSheetImportTest extends TestCase
{
    use RefreshDatabase;

    private function desk(): User
    {
        return User::factory()->create(['job_role' => User::JOB_SUPPLY_CHAIN, 'is_active' => true]);
    }

    private function sheet(string $body): UploadedFile
    {
        $header = ",MOCK UP,TYPE OF FABRIC,DESCRIPTION,BEG BAL,RECEIVED,TOTAL,LESS,REMAINING,REMARKS,DATE,NOTES\n"
            .",,,,13205,21248,34453,22003,12450,,,\n";   // the sheet's totals line

        $path = tempnam(sys_get_temp_dir(), 'sheet').'.csv';
        file_put_contents($path, $header.$body);

        return new UploadedFile($path, 'RAW MATERIALS STOCKS.csv', 'text/csv', null, true);
    }

    public function test_the_stock_sheet_layout_is_recognised_and_loaded(): void
    {
        $this->actingAs($this->desk())->post('/inventory-import', [
            'file' => $this->sheet(
                ",,COTTON SHIRT,AIIZ SHIRT RN BLACK - XS,13,31,44,34,10,,8/3/2026,\n"
            ),
        ])->assertRedirect();

        $item = InventoryItem::where('name', 'AIIZ SHIRT RN BLACK - XS')->first();

        $this->assertNotNull($item, 'the material should have been created');
        $this->assertSame('COTTON SHIRT', $item->category);
        $this->assertEqualsWithDelta(13.0, (float) $item->beginning_stock, 0.01);
        // 13 + 31 − 34 = 10, which is the sheet's REMAINING.
        $this->assertEqualsWithDelta(10.0, (float) $item->quantity, 0.01);
    }

    public function test_the_group_carries_down_to_the_rows_beneath_it(): void
    {
        $this->actingAs($this->desk())->post('/inventory-import', [
            'file' => $this->sheet(
                ",,COTTON SHIRT,AIIZ SHIRT RN BLACK - XS,13,31,44,34,10,,8/3/2026,\n"
                .",,,AIIZ SHIRT RN BLACK - S,8,516,524,524,0,,8/3/2026,\n"
                .",,,AIIZ SHIRT RN BLACK - M,5,692,697,677,20,,8/3/2026,\n"
                .",,MUGS,WHITE MUG 11OZ,4,0,4,0,4,,8/3/2026,\n"
                .",,,BLACK MUG 11OZ,2,0,2,0,2,,8/3/2026,\n"
            ),
        ]);

        // Blank group cells inherit the block above them.
        $this->assertSame('COTTON SHIRT', InventoryItem::where('name', 'AIIZ SHIRT RN BLACK - S')->value('category'));
        $this->assertSame('COTTON SHIRT', InventoryItem::where('name', 'AIIZ SHIRT RN BLACK - M')->value('category'));
        // …until a new group starts.
        $this->assertSame('MUGS', InventoryItem::where('name', 'BLACK MUG 11OZ')->value('category'));
        $this->assertSame(5, InventoryItem::count());
    }

    public function test_the_sheets_own_totals_line_is_not_imported(): void
    {
        $this->actingAs($this->desk())->post('/inventory-import', [
            'file' => $this->sheet(",,COTTON SHIRT,AIIZ SHIRT RN BLACK - XS,13,31,44,34,10,,8/3/2026,\n"),
        ]);

        // The header block carries a totals row with no description.
        $this->assertSame(1, InventoryItem::count());
    }

    public function test_received_and_less_come_back_out_matching_the_sheet(): void
    {
        $this->actingAs($this->desk())->post('/inventory-import', [
            'file' => $this->sheet(",,COTTON SHIRT,AIIZ SHIRT RN BLACK - M,5,692,697,677,20,,8/3/2026,\n"),
        ]);

        $item = InventoryItem::where('name', 'AIIZ SHIRT RN BLACK - M')->firstOrFail();

        $this->assertEqualsWithDelta(5.0, (float) $item->beginning_stock, 0.01, 'BEG BAL');
        $this->assertEqualsWithDelta(692.0, $item->receivedTotal(), 0.01, 'RECEIVED');
        $this->assertEqualsWithDelta(697.0, $item->runningTotal(), 0.01, 'TOTAL');
        $this->assertEqualsWithDelta(677.0, $item->lessTotal(), 0.01, 'LESS');
        $this->assertEqualsWithDelta(20.0, (float) $item->quantity, 0.01, 'REMAINING');
    }

    public function test_re_importing_the_sheet_refreshes_rather_than_doubling(): void
    {
        $user = $this->desk();

        $this->actingAs($user)->post('/inventory-import', [
            'file' => $this->sheet(",,COTTON SHIRT,AIIZ SHIRT RN BLACK - M,5,692,697,677,20,,8/3/2026,\n"),
        ]);
        // The next day's export, with the numbers moved on.
        $this->actingAs($user)->post('/inventory-import', [
            'file' => $this->sheet(",,COTTON SHIRT,AIIZ SHIRT RN BLACK - M,5,700,705,680,25,,8/4/2026,\n"),
        ]);

        $item = InventoryItem::where('name', 'AIIZ SHIRT RN BLACK - M')->firstOrFail();

        $this->assertSame(1, InventoryItem::count(), 'the material must not be duplicated');
        $this->assertEqualsWithDelta(700.0, $item->receivedTotal(), 0.01);
        $this->assertEqualsWithDelta(25.0, (float) $item->quantity, 0.01);
    }

    public function test_an_unknown_group_is_left_unset_rather_than_invented(): void
    {
        $this->actingAs($this->desk())->post('/inventory-import', [
            'file' => $this->sheet(",,SOMETHING NEW,MYSTERY ITEM,1,2,3,1,2,,8/3/2026,\n"),
        ]);

        $item = InventoryItem::where('name', 'MYSTERY ITEM')->firstOrFail();

        $this->assertNull($item->category, 'an unrecognised group should not be stored as a category');
        $this->assertEqualsWithDelta(2.0, (float) $item->quantity, 0.01, 'the stock still loads');
    }

    public function test_only_the_raw_materials_desk_can_import_the_sheet(): void
    {
        $artist = User::factory()->create(['job_role' => User::JOB_ARTIST, 'is_active' => true]);

        $this->actingAs($artist)->post('/inventory-import', [
            'file' => $this->sheet(",,COTTON SHIRT,AIIZ SHIRT RN BLACK - XS,13,31,44,34,10,,8/3/2026,\n"),
        ])->assertForbidden();

        $this->assertSame(0, InventoryItem::count());
    }
}
