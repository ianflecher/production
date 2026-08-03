<?php

namespace Tests\Feature;

use App\Models\InventoryItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Raw-materials inventory: access rules and stock changes. */
class InventoryTest extends TestCase
{
    use RefreshDatabase;

    private function supplyChain(): User
    {
        return User::factory()->create(['job_role' => User::JOB_SUPPLY_CHAIN, 'is_active' => true]);
    }

    private function item(array $o = []): array
    {
        return array_merge([
            'name' => 'Cotton Fabric',
            // Categories come from the shop's stock sheet groups.
            'category' => 'COTTON SHIRT',
            'unit' => 'yards',
            'quantity' => 100,
            'operator_name' => 'Juan',
        ], $o);
    }

    public function test_supply_chain_can_add_a_material(): void
    {
        $this->actingAs($this->supplyChain())->post('/inventory', $this->item())->assertRedirect();

        $this->assertDatabaseHas('inventory_items', ['name' => 'Cotton Fabric', 'unit' => 'yards']);
    }

    public function test_adding_a_material_records_the_opening_stock(): void
    {
        $this->actingAs($this->supplyChain())->post('/inventory', $this->item(['quantity' => 250]));

        $item = InventoryItem::where('name', 'Cotton Fabric')->firstOrFail();
        $this->assertEqualsWithDelta(250.0, (float) $item->beginning_stock, 0.01);
    }

    public function test_material_name_must_be_unique(): void
    {
        $user = $this->supplyChain();
        $this->actingAs($user)->post('/inventory', $this->item());
        $this->actingAs($user)->post('/inventory', $this->item())->assertInvalid(['name']);

        $this->assertSame(1, InventoryItem::count());
    }

    public function test_material_requires_core_fields(): void
    {
        $this->actingAs($this->supplyChain())->post('/inventory', [])
            ->assertInvalid(['name', 'category', 'unit', 'quantity', 'operator_name']);
    }

    // ---- Adding a photo to an existing material ---------------------------

    public function test_a_photo_can_be_added_to_a_material_that_has_none(): void
    {
        \Illuminate\Support\Facades\Storage::fake('public');
        $user = $this->supplyChain();
        $this->actingAs($user)->post('/inventory', $this->item());
        $item = InventoryItem::firstOrFail();
        $this->assertNull($item->photo, 'imported materials start without a picture');

        $this->actingAs($user)->post("/inventory/{$item->id}", [
            'quantity' => (float) $item->quantity,
            'unit' => $item->unit,
            'operator_name' => 'Juan',
            'photo' => \Illuminate\Http\UploadedFile::fake()->image('fabric.jpg'),
        ])->assertRedirect();

        $item->refresh();
        $this->assertNotNull($item->photo);
        \Illuminate\Support\Facades\Storage::disk('public')->assertExists($item->photo);
    }

    public function test_replacing_a_photo_deletes_the_old_file(): void
    {
        \Illuminate\Support\Facades\Storage::fake('public');
        $user = $this->supplyChain();
        $this->actingAs($user)->post('/inventory', $this->item());
        $item = InventoryItem::firstOrFail();

        $payload = fn ($file) => [
            'quantity' => (float) $item->quantity,
            'unit' => $item->unit,
            'operator_name' => 'Juan',
            'photo' => $file,
        ];

        $this->actingAs($user)->post("/inventory/{$item->id}", $payload(\Illuminate\Http\UploadedFile::fake()->image('one.jpg')));
        $first = $item->fresh()->photo;

        $this->actingAs($user)->post("/inventory/{$item->id}", $payload(\Illuminate\Http\UploadedFile::fake()->image('two.jpg')));
        $second = $item->fresh()->photo;

        $this->assertNotSame($first, $second);
        \Illuminate\Support\Facades\Storage::disk('public')->assertMissing($first);
        \Illuminate\Support\Facades\Storage::disk('public')->assertExists($second);
    }

    public function test_adding_only_a_photo_leaves_the_stock_alone(): void
    {
        \Illuminate\Support\Facades\Storage::fake('public');
        $user = $this->supplyChain();
        $this->actingAs($user)->post('/inventory', $this->item(['quantity' => 100]));
        $item = InventoryItem::firstOrFail();

        $this->actingAs($user)->post("/inventory/{$item->id}", [
            'quantity' => 100,          // unchanged
            'unit' => $item->unit,
            'operator_name' => 'Juan',
            'photo' => \Illuminate\Http\UploadedFile::fake()->image('fabric.jpg'),
        ]);

        $this->assertEqualsWithDelta(100.0, (float) $item->fresh()->quantity, 0.01);
        $this->assertNotNull($item->fresh()->photo);
    }

    public function test_a_non_image_is_refused_as_a_photo(): void
    {
        \Illuminate\Support\Facades\Storage::fake('public');
        $user = $this->supplyChain();
        $this->actingAs($user)->post('/inventory', $this->item());
        $item = InventoryItem::firstOrFail();

        $this->actingAs($user)->post("/inventory/{$item->id}", [
            'quantity' => (float) $item->quantity,
            'unit' => $item->unit,
            'operator_name' => 'Juan',
            'photo' => \Illuminate\Http\UploadedFile::fake()->create('notes.pdf', 30),
        ])->assertInvalid(['photo']);

        $this->assertNull($item->fresh()->photo);
    }

    public function test_artist_cannot_access_raw_materials_inventory(): void
    {
        $artist = User::factory()->create(['job_role' => User::JOB_ARTIST, 'is_active' => true]);

        $this->actingAs($artist)->get('/inventory')->assertForbidden();
        $this->actingAs($artist)->post('/inventory', $this->item())->assertForbidden();
    }

    public function test_sales_cannot_access_raw_materials_inventory(): void
    {
        $sales = User::factory()->create(['job_role' => User::ROLE_SALES, 'is_active' => true]);

        $this->actingAs($sales)->get('/inventory')->assertForbidden();
    }

    public function test_leader_can_access_inventory(): void
    {
        $leader = User::factory()->create(['job_role' => User::ROLE_LEADER, 'is_active' => true]);

        $this->actingAs($leader)->get('/inventory')->assertOk();
    }
}
