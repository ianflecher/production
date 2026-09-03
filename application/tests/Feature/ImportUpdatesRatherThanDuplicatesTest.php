<?php

namespace Tests\Feature;

use App\Models\InventoryItem;
use App\Models\User;
use App\Services\MaterialName;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * Importing a sheet again updates the material it already has.
 *
 * The stock sheet is typed by hand and re-exported, so the same fabric comes
 * back as "Cotton White XL", "cotton white  xl" and "COTTON-WHITE-XL" on three
 * different days. Matched literally each of those is a NEW material, and the
 * shop ends up with three rows for one bolt of cloth, none of them holding the
 * true quantity.
 */
class ImportUpdatesRatherThanDuplicatesTest extends TestCase
{
    use RefreshDatabase;

    private function desk(): User
    {
        return User::factory()->create(['job_role' => 'raw materials', 'is_active' => true]);
    }

    private function csv(string $body): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('stock.csv', $body);
    }

    private function import(User $user, string $body)
    {
        return $this->actingAs($user)->post(route('inventory.import'), ['file' => $this->csv($body)]);
    }

    // ---- the key itself -------------------------------------------------

    public function test_the_same_material_written_three_ways_is_one_key(): void
    {
        $key = MaterialName::key('Cotton White XL');

        $this->assertSame($key, MaterialName::key('cotton white  xl'));
        $this->assertSame($key, MaterialName::key('COTTON-WHITE-XL'));
        $this->assertSame($key, MaterialName::key("  Cotton   White  XL  "));
    }

    public function test_genuinely_different_materials_stay_apart(): void
    {
        $this->assertNotSame(MaterialName::key('Cotton White XL'), MaterialName::key('Cotton White L'));
        $this->assertNotSame(MaterialName::key('Cotton White XL'), MaterialName::key('Cotton Black XL'));
    }

    // ---- the import -----------------------------------------------------

    public function test_reimporting_the_same_sheet_updates_and_does_not_duplicate(): void
    {
        $desk = $this->desk();

        $this->import($desk, "name,unit,quantity\nCotton White XL,pcs,100\n")->assertRedirect();
        $this->assertSame(1, InventoryItem::count());

        $this->import($desk, "name,unit,quantity\nCotton White XL,pcs,80\n")->assertRedirect();

        $this->assertSame(1, InventoryItem::count(), 'still one material');
        $this->assertEqualsWithDelta(80, (float) InventoryItem::first()->quantity, 0.01);
    }

    public function test_a_sloppier_spelling_lands_on_the_same_material(): void
    {
        $desk = $this->desk();

        $this->import($desk, "name,unit,quantity\nCotton White XL,pcs,100\n")->assertRedirect();

        // Same cloth, typed differently on a later sheet.
        $this->import($desk, "name,unit,quantity\ncotton white  xl,pcs,60\n")->assertRedirect();

        $this->assertSame(1, InventoryItem::count(), 'one bolt of cloth, one row');
        $this->assertEqualsWithDelta(60, (float) InventoryItem::first()->quantity, 0.01);
    }

    public function test_the_name_the_shop_typed_is_kept(): void
    {
        $desk = $this->desk();

        $this->import($desk, "name,unit,quantity\nCotton White XL,pcs,100\n")->assertRedirect();
        $this->import($desk, "name,unit,quantity\nCOTTON-WHITE-XL,pcs,60\n")->assertRedirect();

        $this->assertSame('Cotton White XL', InventoryItem::first()->name,
            'a re-import does not undo a name the shop tidied');
    }

    public function test_one_sheet_naming_the_same_material_twice_makes_one_row(): void
    {
        $desk = $this->desk();

        $this->import($desk, "name,unit,quantity\nCotton White XL,pcs,100\ncotton white xl,pcs,25\n")
            ->assertRedirect();

        $this->assertSame(1, InventoryItem::count());
        $this->assertEqualsWithDelta(25, (float) InventoryItem::first()->quantity, 0.01,
            'the last line for that material wins');
    }

    public function test_a_new_material_is_still_created(): void
    {
        $desk = $this->desk();

        $this->import($desk, "name,unit,quantity\nCotton White XL,pcs,100\n")->assertRedirect();
        $this->import($desk, "name,unit,quantity\nCotton Black L,pcs,40\n")->assertRedirect();

        $this->assertSame(2, InventoryItem::count(), 'different cloth is a different row');
    }

    public function test_a_removed_material_comes_back_rather_than_arriving_twice(): void
    {
        $desk = $this->desk();

        $this->import($desk, "name,unit,quantity\nCotton White XL,pcs,100\n")->assertRedirect();
        InventoryItem::first()->delete();

        $this->assertSame(0, InventoryItem::count());
        $this->assertSame(1, InventoryItem::withTrashed()->count());

        $this->import($desk, "name,unit,quantity\ncotton white xl,pcs,55\n")->assertRedirect();

        $this->assertSame(1, InventoryItem::withTrashed()->count(),
            'it is the same material, not a second one beside the deleted row');
    }
}
