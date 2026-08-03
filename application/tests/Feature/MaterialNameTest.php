<?php

namespace Tests\Feature;

use App\Models\InventoryItem;
use App\Models\User;
use App\Services\MaterialName;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * The stock sheet keeps size and colour inside one description cell, so the
 * inventory's size/colour filters had nothing to work with. Both are read off
 * the name — and nothing is guessed: an unrecognised size or colour is left
 * blank rather than filled in wrongly.
 */
class MaterialNameTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_size_is_taken_from_the_end_of_the_name(): void
    {
        $this->assertSame('XS', MaterialName::size('AIIZ SHIRT RN BLACK - XS'));
        $this->assertSame('2XL', MaterialName::size('AIIZ SHIRT RN BLACK - 2XL'));
        $this->assertSame('5XL', MaterialName::size('WINNER HOODIE W/ ZIPPER BLACK - 5XL'));
        $this->assertSame('M', MaterialName::size('NB HEATHER RED RN SHIRT - M'));
    }

    public function test_a_measurement_is_not_mistaken_for_a_size(): void
    {
        // These trail a dash too, but they are dimensions, not sizes.
        $this->assertNull(MaterialName::size('THERMAL PAPER - 53X26 INCH'));
        $this->assertNull(MaterialName::size('HOT MELT - 3/4 INCH'));
        $this->assertNull(MaterialName::size('MOUSE PAD - PER 1 PC'));
    }

    public function test_a_name_with_no_size_gives_none(): void
    {
        $this->assertNull(MaterialName::size('WHITE MUG 11OZ'));
    }

    public function test_the_colour_is_recognised_inside_the_name(): void
    {
        $this->assertSame('BLACK', MaterialName::color('AIIZ SHIRT RN BLACK - XS'));
        $this->assertSame('WHITE', MaterialName::color('AIIZ SHIRT RN WHITE - M'));
        $this->assertSame('NAVY', MaterialName::color('GILDAN TEE NAVY - L'));
    }

    public function test_a_two_word_colour_beats_the_single_word_inside_it(): void
    {
        $this->assertSame('HEATHER RED', MaterialName::color('NB HEATHER RED RN SHIRT - 3XL'));
        $this->assertSame('OLD ROSE', MaterialName::color('BLUPRINT CVC PREM OLD ROSE - 2XS'));
        $this->assertSame('ROYAL BLUE', MaterialName::color('GILDAN ROYAL BLUE TEE - M'));
    }

    public function test_the_sheets_own_spellings_are_understood(): void
    {
        $this->assertSame('LAVANDER', MaterialName::color('AIIZ SHIRT RN LAVANDER - XS'));
        $this->assertSame('BURGANDY', MaterialName::color('WINNER HOODIE BURGANDY - L'));
    }

    public function test_an_item_with_no_colour_is_left_blank(): void
    {
        // A flag pole or a magnet genuinely has no colour in the sheet.
        $this->assertNull(MaterialName::color('FLAG POLE 6FT'));
        $this->assertNull(MaterialName::color('MAGNET 18X22X5MM'));
    }

    public function test_the_colour_is_never_taken_from_the_size_part(): void
    {
        // "L" is a size; it must not be read as part of a colour.
        $this->assertNull(MaterialName::color('PLAIN SHIRT - L'));
    }

    // ---- Through the import -----------------------------------------------

    public function test_importing_fills_in_size_and_colour(): void
    {
        $desk = User::factory()->create(['job_role' => User::JOB_SUPPLY_CHAIN, 'is_active' => true]);

        $csv = ",MOCK UP,TYPE OF FABRIC,DESCRIPTION,BEG BAL,RECEIVED,TOTAL,LESS,REMAINING,REMARKS,DATE,NOTES\n"
            .",,,,0,0,0,0,0,,,\n"
            .",,COTTON SHIRT,AIIZ SHIRT RN BLACK - 2XL,10,0,10,0,10,,8/3/2026,\n"
            .",,,NB HEATHER RED RN SHIRT - M,5,0,5,0,5,,8/3/2026,\n"
            .",,MUGS,WHITE MUG 11OZ,3,0,3,0,3,,8/3/2026,\n";

        $path = tempnam(sys_get_temp_dir(), 'sheet').'.csv';
        file_put_contents($path, $csv);

        $this->actingAs($desk)->post('/inventory-import', [
            'file' => new UploadedFile($path, 'stock.csv', 'text/csv', null, true),
        ])->assertRedirect();

        $shirt = InventoryItem::where('name', 'AIIZ SHIRT RN BLACK - 2XL')->firstOrFail();
        $this->assertSame('2XL', $shirt->size);
        $this->assertSame('BLACK', $shirt->color);

        $heather = InventoryItem::where('name', 'NB HEATHER RED RN SHIRT - M')->firstOrFail();
        $this->assertSame('M', $heather->size);
        $this->assertSame('HEATHER RED', $heather->color);

        // A mug has neither, and is left alone rather than guessed at.
        $mug = InventoryItem::where('name', 'WHITE MUG 11OZ')->firstOrFail();
        $this->assertNull($mug->size);
        $this->assertSame('WHITE', $mug->color);
    }
}
