<?php

namespace Tests\Feature;

use App\Models\ProductionOrder;
use App\Models\Task;
use App\Models\TechPack;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * An imported tech pack is not asked for what is already in the picture.
 *
 * When the supplier has made the whole pack as an image, that image replaces
 * the built-in sheet — the page hides it entirely, keeping only the file
 * location box. But the submit check went on demanding all seventeen manual
 * fields, so it was a door with no handle: refused, naming fields the reader
 * could not see anywhere on the page.
 *
 * What people did instead is on the live board. IC2026-01102 and IC2026-00007
 * each have FIFTEEN of the seventeen typed as "N/A" — the only way through was
 * to fill every box with nothing. That is worse than not asking: it puts false
 * answers on the record, and it teaches everybody that the list means nothing.
 * IC2026-00009 is the same shape with the boxes still blank, stuck.
 *
 * The one field kept is the file location, for the reason the import panel
 * already gives on screen: the printer opens the print-ready files from that
 * path, and nothing can read it off a flattened picture.
 */
class AnImportedPackIsAlreadyTheSheetTest extends TestCase
{
    use RefreshDatabase;

    private function artist(): User
    {
        return User::factory()->create(['job_role' => User::JOB_ARTIST, 'is_active' => true]);
    }

    /** An order whose pack is with its artist, with every manual box empty. */
    private function packWithTheArtist(User $artist, bool $imported): Task
    {
        $officer = User::factory()->create(['job_role' => User::ROLE_SALES, 'is_active' => true]);

        $order = ProductionOrder::create([
            'order_number' => 'IC2026-0'.random_int(1000, 9999),
            'customer_name' => 'Imported Co',
            'product_type' => 'round_neck',
            'quantity' => 40,
            'due_date' => now()->addWeeks(2),
            'created_by' => $officer->id,
            'status' => 'active',
        ]);

        $order->jobOrder()->create(['status' => 'sent_to_artist', 'created_by' => $officer->id]);

        $order->techPacks()->create([
            'phase' => TechPack::PHASE_SAMPLE,
            'imported_pack_path' => $imported ? 'imported-tech-packs/whole-sheet.jpg' : null,
            'imported_pack_name' => $imported ? 'whole-sheet.jpg' : null,
            'file_location_notes' => '\\\\192.168.1.9\\Designs\\job.tif',
        ]);

        return Task::create([
            'production_order_id' => $order->id,
            'department' => 'Tech pack',
            'team' => User::JOB_ARTIST,
            'stage' => 2,
            'sequence' => 20,
            'status' => 'in_progress',
            'assigned_to' => $artist->id,
            'approver_role' => 'sales',
        ]);
    }

    /* ---------------- the door with no handle ---------------- */

    /**
     * The bug. Every manual box is empty, but the picture carries them, so
     * the submit goes through.
     */
    public function test_an_imported_pack_submits_without_the_sixteen_boxes(): void
    {
        $artist = $this->artist();
        $task = $this->packWithTheArtist($artist, imported: true);

        $this->actingAs($artist)
            ->post(route('tasks.submit', $task->id))
            ->assertSessionHasNoErrors();

        $this->assertSame('for_checking', $task->fresh()->status);
    }

    /**
     * And the same pack WITHOUT the image is still held to all seventeen —
     * the built-in sheet is on the page, so there is somewhere to answer.
     */
    public function test_a_typed_pack_is_still_held_to_the_whole_list(): void
    {
        $artist = $this->artist();
        $task = $this->packWithTheArtist($artist, imported: false);

        $this->actingAs($artist)
            ->post(route('tasks.submit', $task->id))
            ->assertSessionHasErrors('tech_pack');

        $this->assertSame('in_progress', $task->fresh()->status,
            'a typed pack went through with fifteen empty boxes');
    }

    /* ---------------- the one thing a picture cannot carry ---------------- */

    /**
     * The file location is still required, and it is the whole reason the
     * import panel has a box on it at all: the printer opens the print-ready
     * files from that path.
     */
    public function test_an_imported_pack_still_needs_its_file_location(): void
    {
        $artist = $this->artist();
        $task = $this->packWithTheArtist($artist, imported: true);

        $task->order->techPacks()->first()->update(['file_location_notes' => null]);

        $response = $this->actingAs($artist)
            ->post(route('tasks.submit', $task->id))
            ->assertSessionHasErrors('tech_pack');

        $this->assertStringContainsString('File location', session('errors')->first('tech_pack'));
        $this->assertSame('in_progress', $task->fresh()->status);
    }

    /** And it is not asked for anything else it cannot be given. */
    public function test_the_refusal_names_only_the_file_location(): void
    {
        $artist = $this->artist();
        $task = $this->packWithTheArtist($artist, imported: true);

        $task->order->techPacks()->first()->update(['file_location_notes' => null]);

        $this->actingAs($artist)->post(route('tasks.submit', $task->id));

        $said = session('errors')->first('tech_pack');

        foreach (['Design name', 'Fitting', 'Print type', 'Fabric', 'Neck type', 'Packaging'] as $box) {
            $this->assertStringNotContainsString($box, $said,
                'the artist was sent to fill in a box that is not on the page');
        }
    }

    /**
     * Removing the image puts the full list back. The pack is the built-in
     * sheet again, so every question has a box again.
     */
    public function test_removing_the_image_brings_the_list_back(): void
    {
        $artist = $this->artist();
        $task = $this->packWithTheArtist($artist, imported: true);

        $task->order->techPacks()->first()->update([
            'imported_pack_path' => null,
            'imported_pack_name' => null,
        ]);

        $this->actingAs($artist)
            ->post(route('tasks.submit', $task->id))
            ->assertSessionHasErrors('tech_pack');
    }
}
