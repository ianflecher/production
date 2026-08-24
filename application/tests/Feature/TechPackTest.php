<?php

namespace Tests\Feature;

use App\Models\ProductionOrder;
use App\Models\Task;
use App\Models\TaskFile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * One tech pack in place of the job order sheet and the mockup page.
 *
 * Making a shirt used to take two open tabs: the artwork on the mockup page and
 * the spec on the job order sheet, read across from one to the other. Neither
 * printed the thing production actually asks for — where the artwork goes and
 * how big it comes out.
 *
 * The artist fills their half in the pack itself, the way the floor fills the
 * seam record: you read the spec and answer it in the same place.
 */
class TechPackTest extends TestCase
{
    use RefreshDatabase;

    private function shop(): array
    {
        $sales = User::factory()->create(['job_role' => User::ROLE_SALES, 'is_active' => true, 'name' => 'Ysabel']);
        $artist = User::factory()->create(['job_role' => User::JOB_ARTIST, 'is_active' => true, 'name' => 'Dave']);

        $order = ProductionOrder::create([
            'order_number' => 'IC2026-04001', 'customer_name' => 'Jordan Soriano',
            'product_type' => 'round_neck', 'quantity' => 40,
            'due_date' => now()->addWeeks(3), 'created_by' => $sales->id, 'status' => 'active',
        ]);

        $order->jobOrder()->create([
            'status' => 'sent_to_artist', 'created_by' => $sales->id,
            'print_type' => 'dtf', 'printer' => 'dtf_printer', 'fabric' => 'Cotton blend',
            'neck' => 'Round neck', 'neck_size' => '1 x 1 ribbings',
            'bottom_hem' => 'Straight cut', 'packaging' => 'Ordinary',
            'free_logo_sticker' => 'IC sticker',
        ]);

        $task = Task::create([
            'production_order_id' => $order->id, 'department' => 'Final mockup',
            'sequence' => 2, 'stage' => 2, 'status' => 'complete', 'approved_at' => now(),
            'team' => User::JOB_ARTIST, 'assigned_to' => $artist->id,
        ]);

        return [$sales, $artist, $order->fresh(), $task];
    }

    public function test_the_pack_carries_the_order_and_the_garment_spec(): void
    {
        [$sales, , $order] = $this->shop();

        $this->actingAs($sales)->get("/orders/{$order->id}/job-order")
            ->assertOk()
            ->assertSee('Jordan Soriano')          // client
            ->assertSee('Ysabel')                  // agent
            ->assertSee('Cotton blend')            // fabric
            ->assertSee('Round neck / 1 x 1 ribbings')
            ->assertSee('Ordinary')
            ->assertSee('IC sticker')
            // The template's own row names, not the job order's.
            ->assertSee('Cutting method')
            ->assertSee('Stitch thread')
            ->assertSee('Size range')
            ->assertSee('Type')
            // Named on the sheet the shop asked for.
            ->assertSee('Printer')
            ->assertSee('Date created')
            ->assertSee('Delivery date');
    }

    public function test_the_banner_offers_the_standard_wording_without_claiming_it(): void
    {
        // "STANDARD DTF PLACING FOR SHIRT" — built rather than retyped, so it
        // cannot say DTF on a sublimation job. But OFFERED, not printed: it
        // used to appear on every sheet whether or not anybody had written it,
        // which reads as a line somebody typed.
        [$sales, $artist, $order, $task] = $this->shop();

        // Nobody has written one, so the sheet the floor reads says nothing.
        $this->actingAs($sales)->get("/orders/{$order->id}/job-order")
            ->assertOk()
            ->assertDontSee('STANDARD DTF PLACING FOR ROUND NECK / V-NECK SHIRT');

        // The artist is still offered it, as a hint in the empty box.
        $this->actingAs($artist)->get("/my-tasks/{$task->id}/job-order")
            ->assertOk()
            ->assertSee('STANDARD DTF PLACING FOR ROUND NECK / V-NECK SHIRT');
    }

    public function test_the_artist_can_edit_the_placement_heading(): void
    {
        [$sales, $artist, $order, $task] = $this->shop();

        $this->actingAs($artist)->get("/my-tasks/{$task->id}/job-order")
            ->assertOk()
            ->assertSee('name="placing_title"', false);

        $this->actingAs($artist)->post("/my-tasks/{$task->id}/tech-pack", [
            'placing_title' => 'Standard silkscreen placing for jacket / hoodie',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->actingAs($sales)->get("/orders/{$order->id}/job-order")
            ->assertOk()
            ->assertSee('STANDARD SILKSCREEN PLACING FOR JACKET / HOODIE');
    }

    public function test_the_artist_fills_their_half_in_the_pack_itself(): void
    {
        [, $artist, $order, $task] = $this->shop();

        $this->actingAs($artist)->get("/my-tasks/{$task->id}/job-order")
            ->assertOk()
            ->assertSee('name="design_name"', false)
            ->assertSee('name="cutting_method"', false)
            ->assertSee('name="zipper_type"', false)
            ->assertSee('name="bottom_hem"', false)
            ->assertSee('name="lip_pocket_color"', false)
            // …around the pack, not instead of it.
            ->assertSee('tp-sheet', false);
    }

    public function test_manual_save_takes_the_artist_to_submit_for_checking(): void
    {
        [, $artist, , $task] = $this->shop();

        // The shared fixture is the approved Final Mockup that unlocks this
        // work. Exercise the save-and-continue flow on the actual next step,
        // where the artist has a Submit Tech Pack action to continue to.
        $task->update([
            'department' => 'Tech pack',
            'status' => 'in_progress',
            'approved_at' => null,
        ]);

        $this->actingAs($artist)->post("/my-tasks/{$task->id}/tech-pack", [
            'design_name' => 'Windbreaker WB-001',
            'finish_editing' => 1,
        ])->assertRedirect(route('tasks.show', $task->id).'#task-action-'.$task->id)
            ->assertSessionHas('success');

        $this->actingAs($artist)->get(route('tasks.show', $task->id))
            ->assertOk()
            ->assertSee('id="task-action-'.$task->id.'"', false)
            ->assertSee('Submit Tech Pack for checking', false);
    }

    public function test_the_account_officer_can_fill_text_but_does_not_get_image_uploads(): void
    {
        [$sales, , $order] = $this->shop();

        $this->actingAs($sales)->get(route('job-orders.edit', $order))
            ->assertOk()
            ->assertSee('name="design_name"', false)
            ->assertSee('name="printer"', false)
            ->assertDontSee('name="front_actual_size"', false)
            ->assertDontSee('name="tech_pack_images[front_mockup]"', false);
    }

    public function test_the_assigned_artist_name_is_automatic(): void
    {
        [$sales, $artist, $order] = $this->shop();

        $this->actingAs($sales)->get(route('job-orders.edit', $order))
            ->assertOk()
            ->assertSee($artist->name)
            ->assertDontSee('name="artist_name"', false);
    }

    public function test_the_pack_uses_the_imprint_customs_wordmark_and_image_uploads(): void
    {
        [, $artist, , $task] = $this->shop();

        $this->actingAs($artist)->get("/my-tasks/{$task->id}/job-order")
            ->assertOk()
            ->assertSee('IMPRINT')
            ->assertSee('CUSTOMS')
            ->assertDontSee('class="tp-logo"', false)
            ->assertSee('name="tech_pack_images[front_mockup]"', false)
            ->assertSee('name="tech_pack_images[front_artwork]"', false)
            ->assertSee('name="tech_pack_images[front_flat]"', false)
            ->assertSee('name="tech_pack_images[file_location_image]"', false)
            ->assertSee('data-preview="tp_preview_front_mockup"', false)
            ->assertSee('name="tech_pack_images[tag_1]"', false)
            ->assertSee('class="tp-ref-image tp-ref-image-artwork is-resizable"', false);
    }

    public function test_an_uploaded_tech_pack_image_is_saved_and_displayed(): void
    {
        Storage::fake('local');
        [$sales, $artist, $order, $task] = $this->shop();

        $this->actingAs($artist)->post("/my-tasks/{$task->id}/tech-pack", [
            'tech_pack_images' => [
                'front_mockup' => UploadedFile::fake()->image('front.png'),
            ],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $image = $order->fresh()->techPack->image_uploads['front_mockup'];
        Storage::disk('local')->assertExists($image['path']);
        $this->assertSame('front.png', $image['name']);

        $url = route('job-orders.tech-pack-image', ['order' => $order, 'slot' => 'front_mockup']);
        $this->actingAs($sales)->get($url)->assertOk();
        $this->actingAs($sales)->get("/orders/{$order->id}/job-order")
            ->assertOk()
            ->assertSee($url, false);
    }

    public function test_the_path_is_built_from_the_machine_name(): void
    {
        [, $artist, $order, $task] = $this->shop();

        $this->actingAs($artist)->post("/my-tasks/{$task->id}/tech-pack", [
            'file_location_host' => 'IC-PRINT-01',
            'file_location_tail' => 'FOR PRINT\IC2026-04001',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(
            '\\\\IC-PRINT-01\FOR PRINT\IC2026-04001',
            $order->fresh()->techPack->file_location_notes
        );
    }

    public function test_a_path_with_no_machine_named_falls_back_to_this_one(): void
    {
        [, $artist, $order, $task] = $this->shop();

        // The name box cannot be left empty on the sheet, but a stale tab or a
        // post without it must not save a path with nothing on the front.
        $this->actingAs($artist)->post("/my-tasks/{$task->id}/tech-pack", [
            'file_location_host' => '',
            'file_location_tail' => 'FOR PRINT',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $saved = (string) $order->fresh()->techPack->file_location_notes;

        $this->assertStringStartsWith('\\', $saved);
        $this->assertStringEndsWith('\FOR PRINT', $saved);
    }

    public function test_a_reader_can_click_a_picture_to_see_it_big(): void
    {
        [$sales, , $order, $task] = $this->shop();

        // The floor reads this at a station, where a garment flat is a couple
        // of centimetres across and the print size on it is unreadable.
        $this->actingAs($sales)->get("/orders/{$order->id}/job-order")
            ->assertOk()
            ->assertSee('id="tpZoom"', false);
    }

    public function test_the_artists_copy_opens_the_file_picker_instead(): void
    {
        [, $artist, , $task] = $this->shop();

        // On their sheet a click on a picture means "replace this", which is
        // what they want it to do — so the viewer is not in their way.
        $this->actingAs($artist)->get("/my-tasks/{$task->id}/job-order")
            ->assertOk()
            ->assertDontSee('id="tpZoom"', false);
    }

    public function test_the_artist_can_add_a_text_block_and_drag_it(): void
    {
        [$sales, $artist, $order, $task] = $this->shop();

        // The sheet names what every job has. This job has something to say
        // that it has no row for.
        $this->actingAs($artist)->post("/my-tasks/{$task->id}/tech-pack", [
            'add_note_box' => 1,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertCount(1, $order->fresh()->techPack->extraNotes());

        $this->actingAs($artist)->post("/my-tasks/{$task->id}/tech-pack", [
            'extra_notes' => ['Ribbing runs 2cm short on this batch'],
            'box_positions' => ['note_0' => ['x' => 6, 'y' => -2]],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $pack = $order->fresh()->techPack;

        $this->assertSame(['Ribbing runs 2cm short on this batch'], $pack->extraNotes());
        $this->assertSame(['x' => 6.0, 'y' => -2.0], $pack->boxPosition('note_0'));

        // And it is on the sheet the floor reads, where it was put.
        $this->actingAs($sales)->get("/orders/{$order->id}/job-order")
            ->assertOk()
            ->assertSee('Ribbing runs 2cm short on this batch');
    }

    public function test_a_text_block_can_be_deleted(): void
    {
        [, $artist, $order, $task] = $this->shop();

        $this->actingAs($artist)->post("/my-tasks/{$task->id}/tech-pack", [
            'extra_notes' => ['Keep this one', 'Delete this one'],
        ])->assertRedirect();

        $this->assertCount(2, $order->fresh()->techPack->extraNotes());

        // The × on the block itself, by its position in the list.
        $this->actingAs($artist)->post("/my-tasks/{$task->id}/tech-pack", [
            'extra_notes' => ['Keep this one', 'Delete this one'],
            'remove_note' => 1,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(['Keep this one'], $order->fresh()->techPack->extraNotes());
    }

    public function test_an_empty_text_block_is_not_kept(): void
    {
        [, $artist, $order, $task] = $this->shop();

        $this->actingAs($artist)->post("/my-tasks/{$task->id}/tech-pack", ['add_note_box' => 1]);

        // Added, never written in, saved again: a blank line on the sheet helps
        // nobody, so it goes.
        $this->actingAs($artist)->post("/my-tasks/{$task->id}/tech-pack", [
            'extra_notes' => [''],
        ])->assertRedirect();

        $this->assertSame([], $order->fresh()->techPack->extraNotes());
    }

    public function test_a_detail_box_can_point_at_the_garment(): void
    {
        [$sales, $artist, $order, $task] = $this->shop();

        // A pack in the trade draws a line from the woven-label box to the
        // collar. Without it the floor matches pictures to places by eye.
        $this->actingAs($artist)->post("/my-tasks/{$task->id}/tech-pack", [
            'callouts' => ['tag_1' => [
                'x' => 22.5, 'y' => 31,
                'mx' => 44.25, 'my' => 18.5,
            ]],
        ])->assertRedirect()->assertSessionHasNoErrors();

        // Both ends are the artist's to place; a line saved with only its far
        // end still reads, with the near one worked out from the box.
        $line = $order->fresh()->techPack->callouts()['tag_1'];
        $this->assertSame(['x' => 22.5, 'y' => 31.0], $line['to']);
        $this->assertNull($line['from']);
        $this->assertSame(['x' => 44.25, 'y' => 18.5], $line['mockup']);

        // The line is on the sheet the floor reads, not just the artist's.
        $this->actingAs($sales)->get("/orders/{$order->id}/job-order")
            ->assertOk()
            ->assertSee('data-pin-slot="tag_1"', false)
            ->assertSee('data-mockup-x="44.25"', false)
            ->assertSee('data-mockup-y="18.5"', false)
            // One drawer serves every copy now: two of them took turns
            // clearing each other's work and the display ended up with none.
            ->assertSee('tpDrawLines', false);
    }

    public function test_a_line_can_be_taken_off_again(): void
    {
        [, $artist, $order, $task] = $this->shop();

        $this->actingAs($artist)->post("/my-tasks/{$task->id}/tech-pack", [
            'callouts' => ['tag_1' => ['x' => 10, 'y' => 10], 'front_artwork' => ['x' => 20, 'y' => 20]],
        ])->assertRedirect();

        $this->actingAs($artist)->post("/my-tasks/{$task->id}/tech-pack", [
            'remove_callout' => 'tag_1',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $lines = $order->fresh()->techPack->callouts();

        $this->assertArrayNotHasKey('tag_1', $lines);
        $this->assertArrayHasKey('front_artwork', $lines, 'only the one that was removed');
    }

    public function test_where_a_box_was_dragged_to_is_kept(): void
    {
        [$sales, $artist, $order, $task] = $this->shop();

        $this->actingAs($artist)->post("/my-tasks/{$task->id}/tech-pack", [
            'box_positions' => [
                'front_artwork' => ['x' => 12.5, 'y' => -4.25],
                'text_banner' => ['x' => 0, 'y' => 3],
            ],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $pack = $order->fresh()->techPack;

        $this->assertSame(['x' => 12.5, 'y' => -4.25], $pack->boxPosition('front_artwork'));
        $this->assertSame(['x' => 0.0, 'y' => 3.0], $pack->boxPosition('text_banner'));
        $this->assertNull($pack->boxPosition('back_artwork'), 'a box nobody moved has no offset');

        // And the sheet everybody else reads is laid out the same way.
        $this->actingAs($sales)->get("/orders/{$order->id}/job-order")
            ->assertOk()
            ->assertSee('translate(12.5cqw,-4.25cqw)', false);
    }

    public function test_a_pinned_text_block_is_held_down_the_sheets_height(): void
    {
        // Across as a share of the WIDTH, down as a share of the HEIGHT. Held
        // by the width both ways, a block pinned under its tag on screen came
        // out several bands lower on paper, where the sheet is a different
        // height for the same width.
        [$sales, $artist, $order, $task] = $this->shop();

        $this->actingAs($artist)->post("/my-tasks/{$task->id}/tech-pack", [
            'box_positions' => [
                'text_tag_1' => ['x' => 39.49, 'y' => 59.15, 'yh' => 71.2],
            ],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $pack = $order->fresh()->techPack;

        $this->assertSame(
            ['x' => 39.49, 'y' => 59.15, 'yh' => 71.2],
            $pack->boxPosition('text_tag_1')
        );
        $this->assertStringContainsString('top:71.2%', $pack->boxPositionStyle('text_tag_1'));
        $this->assertStringContainsString('left:39.49cqw', $pack->boxPositionStyle('text_tag_1'));

        // The copy the floor reads is pinned the same way.
        $this->actingAs($sales)->get("/orders/{$order->id}/job-order")
            ->assertOk()
            ->assertSee('top:71.2%', false);
    }

    public function test_a_block_pinned_before_the_height_figure_still_reads(): void
    {
        // Nobody's sheet moves on its own: a block saved with only the old
        // share-of-width figure goes on being placed by it.
        [, $artist, $order, $task] = $this->shop();

        $this->actingAs($artist)->post("/my-tasks/{$task->id}/tech-pack", [
            'box_positions' => ['text_tag_2' => ['x' => 12.0, 'y' => 40.0]],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $pack = $order->fresh()->techPack;

        $this->assertSame(['x' => 12.0, 'y' => 40.0], $pack->boxPosition('text_tag_2'));
        $this->assertStringContainsString('top:40cqw', $pack->boxPositionStyle('text_tag_2'));
    }

    public function test_a_picture_box_is_still_nudged_by_the_width_alone(): void
    {
        // Only text blocks are pinned. A picture box keeps its place in the
        // grid and is nudged from there, which scales with the width on both
        // copies — a height figure would be wrong for it.
        [, $artist, $order, $task] = $this->shop();

        $this->actingAs($artist)->post("/my-tasks/{$task->id}/tech-pack", [
            'box_positions' => ['front_artwork' => ['x' => 5.0, 'y' => 6.0, 'yh' => 80.0]],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $pack = $order->fresh()->techPack;

        $this->assertSame(
            'transform:translate(5cqw,6cqw);',
            $pack->boxPositionStyle('front_artwork')
        );
    }

    public function test_moving_one_box_leaves_the_others_where_they_were(): void
    {
        [, $artist, $order, $task] = $this->shop();

        $this->actingAs($artist)->post("/my-tasks/{$task->id}/tech-pack", [
            'box_positions' => ['tag_1' => ['x' => 5, 'y' => 5]],
        ])->assertRedirect();

        // A second save that mentions only one box must not snap the rest home.
        $this->actingAs($artist)->post("/my-tasks/{$task->id}/tech-pack", [
            'box_positions' => ['tag_2' => ['x' => -3, 'y' => 2]],
        ])->assertRedirect();

        $pack = $order->fresh()->techPack;

        $this->assertSame(['x' => 5.0, 'y' => 5.0], $pack->boxPosition('tag_1'));
        $this->assertSame(['x' => -3.0, 'y' => 2.0], $pack->boxPosition('tag_2'));
    }

    public function test_the_x_takes_a_tag_box_off_the_sheet(): void
    {
        [$sales, $artist, $order, $task] = $this->shop();

        // The sample panel is a list of boxes, so one comes off that list. A
        // tag is part of the sheet — removing it used to empty the box and draw
        // it again, which looked exactly like nothing happening.
        $this->actingAs($artist)->post("/my-tasks/{$task->id}/tech-pack", [
            'remove_image' => 'tag_2',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $pack = $order->fresh()->techPack;

        $this->assertTrue($pack->boxIsHidden('tag_2'));
        $this->assertFalse($pack->boxIsHidden('tag_1'), 'only the box that was pressed');

        // And it is off the sheet everybody else reads, not just the artist's.
        $this->actingAs($sales)->get("/orders/{$order->id}/job-order")
            ->assertOk()
            ->assertDontSee('tp_preview_tag_2', false)
            ->assertSee('tp_preview_tag_1', false);
    }

    public function test_the_x_empties_the_box_before_it_removes_it(): void
    {
        Storage::fake('local');
        [, $artist, $order, $task] = $this->shop();

        $this->actingAs($artist)->post("/my-tasks/{$task->id}/tech-pack", [
            'tech_pack_images' => ['file_location_image' => UploadedFile::fake()->image('folder.png')],
        ])->assertRedirect();

        // First press: the picture goes, the box stays. One stray click used to
        // take the whole panel away, which from the artist's chair reads as
        // "I can no longer add a picture here".
        $this->actingAs($artist)->post("/my-tasks/{$task->id}/tech-pack", [
            'remove_image' => 'file_location_image',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $pack = $order->fresh()->techPack;
        $this->assertArrayNotHasKey('file_location_image', (array) $pack->image_uploads);
        $this->assertFalse($pack->boxIsHidden('file_location_image'), 'the box stays after the picture goes');

        // Second press, on an empty box: now the box itself goes.
        $this->actingAs($artist)->post("/my-tasks/{$task->id}/tech-pack", [
            'remove_image' => 'file_location_image',
        ])->assertRedirect();

        $this->assertTrue($order->fresh()->techPack->boxIsHidden('file_location_image'));
    }

    public function test_a_box_taken_off_can_be_put_back(): void
    {
        [, $artist, $order, $task] = $this->shop();

        $this->actingAs($artist)->post("/my-tasks/{$task->id}/tech-pack", [
            'remove_image' => 'front_artwork',
        ])->assertRedirect();

        $this->assertTrue($order->fresh()->techPack->boxIsHidden('front_artwork'));

        $this->actingAs($artist)->post("/my-tasks/{$task->id}/tech-pack", [
            'restore_image_box' => 'front_artwork',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertFalse($order->fresh()->techPack->boxIsHidden('front_artwork'),
            'a box taken off by mistake must not be gone for good');
    }

    public function test_the_artist_can_add_and_remove_a_sample_box(): void
    {
        [, $artist, $order, $task] = $this->shop();

        // One box stands until somebody adds another.
        $this->assertSame(['front_flat'], $order->techPackOrNew()->sampleBoxes());

        $this->actingAs($artist)->post("/my-tasks/{$task->id}/tech-pack", [
            'add_image_box' => 1,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $boxes = $order->fresh()->techPack->sampleBoxes();
        $this->assertCount(2, $boxes);
        $this->assertSame('back_flat', end($boxes));

        $this->actingAs($artist)->post("/my-tasks/{$task->id}/tech-pack", [
            'remove_image' => 'back_flat',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $left = $order->fresh()->techPack->sampleBoxes();
        $this->assertNotContains('back_flat', $left);
        $this->assertCount(1, $left);
    }

    public function test_the_artist_can_empty_one_image_box(): void
    {
        Storage::fake('local');
        [$sales, $artist, $order, $task] = $this->shop();

        $this->actingAs($artist)->post("/my-tasks/{$task->id}/tech-pack", [
            'tech_pack_images' => [
                'front_mockup' => UploadedFile::fake()->image('front.png'),
                'tag_1' => UploadedFile::fake()->image('tag.png'),
            ],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $uploads = $order->fresh()->techPack->image_uploads;
        $gone = $uploads['front_mockup']['path'];

        $this->actingAs($artist)->post("/my-tasks/{$task->id}/tech-pack", [
            'remove_image' => 'front_mockup',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $left = $order->fresh()->techPack->image_uploads;

        // The box it emptied, and only that box.
        $this->assertArrayNotHasKey('front_mockup', $left);
        $this->assertSame($uploads['tag_1']['path'], $left['tag_1']['path']);

        // The file goes with the slot rather than sitting on the disk forever.
        Storage::disk('local')->assertMissing($gone);
    }

    public function test_a_made_up_image_slot_cannot_be_emptied(): void
    {
        [, $artist, , $task] = $this->shop();

        $this->actingAs($artist)->post("/my-tasks/{$task->id}/tech-pack", [
            'remove_image' => 'not_a_slot',
        ])->assertSessionHasErrors('remove_image');
    }

    public function test_final_mockup_task_files_are_not_inserted_automatically(): void
    {
        [$sales, , $order, $task] = $this->shop();
        $file = TaskFile::create([
            'task_id' => $task->id,
            'label' => 'Final mockup file',
            'original_name' => 'full-layout-sheet.jpg',
            'path' => 'task-files/full-layout-sheet.jpg',
            'mime' => 'image/jpeg',
            'size' => 1024,
            'round' => 1,
        ]);

        $this->actingAs($sales)->get("/orders/{$order->id}/job-order")
            ->assertOk()
            ->assertSee('id="tp_preview_front_mockup" src=""', false)
            ->assertSee('Approved mockup image');
    }

    public function test_the_old_custom_board_is_not_rendered_on_the_reference_sheet(): void
    {
        [, $artist, , $task] = $this->shop();

        $this->actingAs($artist)->get("/my-tasks/{$task->id}/job-order")
            ->assertOk()
            ->assertDontSee('name="tech_pack_images[bottom_image]"', false)
            ->assertDontSee('name="bottom_text"', false)
            ->assertDontSee('Additional tech notes')
            ->assertSee('File location');
    }

    public function test_everybody_else_reads_it(): void
    {
        [$sales, , $order] = $this->shop();

        $this->actingAs($sales)->get("/orders/{$order->id}/job-order")
            ->assertOk()
            ->assertDontSee('name="design_name"', false);
    }

    public function test_what_the_artist_types_is_saved_and_printed(): void
    {
        [$sales, $artist, $order, $task] = $this->shop();

        $this->actingAs($artist)->post("/my-tasks/{$task->id}/tech-pack", [
            'design_name' => 'Aerox Lifestyle (White/08 1426)',
            'fitting' => 'Original fit',
            'stitch_thread' => 'N/A',
            'cutting_method' => 'Straight cut',
            'size_range' => 'M-2XL',
            'back_print_placement' => 'Back',
            'back_actual_size' => '14.0\" W x 10.633\" H',
            'front_print_placement' => 'Left chest',
            'front_actual_size' => '4.0\" W x 2.318\" H',
            'artist_name' => 'Dave CAD Mick',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->actingAs($sales)->get("/orders/{$order->id}/job-order")
            ->assertOk()
            ->assertSee('Aerox Lifestyle (White/08 1426)')
            ->assertSee('Original fit')
            ->assertSee('M-2XL');
    }

    public function test_another_artist_cannot_fill_somebody_elses_pack(): void
    {
        [, , , $task] = $this->shop();
        $other = User::factory()->create(['job_role' => User::JOB_ARTIST, 'is_active' => true]);

        $this->actingAs($other)->post("/my-tasks/{$task->id}/tech-pack", [
            'design_name' => 'Sneaky',
        ])->assertNotFound();
    }

    public function test_the_folder_picture_is_optional_and_replaces_the_old_one(): void
    {
        Storage::fake('local');
        [, $artist, $order, $task] = $this->shop();

        $this->actingAs($artist)->post("/my-tasks/{$task->id}/tech-pack", [
            'folder_shot' => UploadedFile::fake()->image('folder.png'),
        ])->assertSessionHasNoErrors();

        $first = $order->fresh()->techPack->folder_shot_path;
        $this->assertNotNull($first);
        Storage::disk('local')->assertExists($first);

        $this->actingAs($artist)->post("/my-tasks/{$task->id}/tech-pack", [
            'folder_shot' => UploadedFile::fake()->image('folder-again.png'),
        ]);

        // The old picture is of a folder that has since changed, which is the
        // reason for uploading a new one.
        Storage::disk('local')->assertMissing($first);
        $this->assertSame('folder-again.png', $order->fresh()->techPack->folder_shot_name);
    }

    public function test_the_reference_sheet_omits_the_old_export_folder_panel(): void
    {
        [, $artist, , $task] = $this->shop();

        $this->actingAs($artist)->get("/my-tasks/{$task->id}/job-order")
            ->assertOk()
            ->assertDontSee('name="folder_shot"', false)
            ->assertDontSee('Export folder image')
            ->assertSee('File location')
            // The machine's address is printed beside the box, and only the
            // folder and file name after it are typed — so the artist cannot
            // delete the part that says which PC the files are on.
            // The machine NAME leads and the address is the alternative below
            // it: a name survives the router moving that PC to a new address.
            ->assertSee('name="file_location_tail"', false)
            ->assertSee('name="file_location_host"', false)
            ->assertDontSee('id="tp_image_bottom_image"', false);
    }

    public function test_the_folder_picture_is_served_not_guessable(): void
    {
        Storage::fake('local');
        [$sales, $artist, $order, $task] = $this->shop();

        $this->actingAs($artist)->post("/my-tasks/{$task->id}/tech-pack", [
            'folder_shot' => UploadedFile::fake()->image('folder.png'),
        ]);

        $this->actingAs($sales)->get("/orders/{$order->id}/folder-shot")->assertOk();
    }

    public function test_asking_for_a_folder_picture_that_does_not_exist_is_a_404(): void
    {
        [$sales, , $order] = $this->shop();

        $this->actingAs($sales)->get("/orders/{$order->id}/folder-shot")->assertNotFound();
    }

    public function test_the_old_mockup_link_still_lands_somewhere_useful(): void
    {
        // Every existing link, button and bookmark keeps working.
        [$sales, , $order] = $this->shop();

        $this->actingAs($sales)->get("/orders/{$order->id}/mockup")
            ->assertRedirect(route('orders.job-order', $order));
    }

    public function test_the_production_record_is_not_shown_under_the_pack(): void
    {
        [$sales, , $order] = $this->shop();

        $this->actingAs($sales)->get("/orders/{$order->id}/job-order")
            ->assertOk()
            ->assertDontSee('Production record')
            ->assertDontSee('class="tp-record"', false);
    }

    public function test_a_pack_with_nothing_filled_in_still_opens(): void
    {
        // The normal state on the day an order is taken.
        $sales = User::factory()->create(['job_role' => User::ROLE_SALES, 'is_active' => true]);
        $order = ProductionOrder::create([
            'order_number' => 'IC2026-04002', 'customer_name' => 'Empty Co',
            'product_type' => 'round_neck', 'quantity' => 5,
            'due_date' => now()->addWeek(), 'created_by' => $sales->id, 'status' => 'active',
        ]);
        $order->jobOrder()->create(['status' => 'draft', 'created_by' => $sales->id]);

        $this->actingAs($sales)->get("/orders/{$order->id}/job-order")
            ->assertOk()
            ->assertSee('Materials and components');
    }

    public function test_the_template_panel_carries_the_artists_template(): void
    {
        // The flats the floor cuts and prints to — a different thing from the
        // mockup, which is what the client approved.
        [$sales, $artist, $order] = $this->shop();

        $template = Task::create([
            'production_order_id' => $order->id, 'department' => 'Tech pack',
            'sequence' => 3, 'stage' => 2, 'status' => 'complete',
            'team' => User::JOB_ARTIST, 'assigned_to' => $artist->id,
        ]);

        $file = TaskFile::create([
            'task_id' => $template->id,
            'label' => 'Template file',
            'original_name' => 'template.jpg',
            'path' => 'task-files/template.jpg',
            'mime' => 'image/jpeg',
            'size' => 1024,
            'round' => 1,
        ]);

        $this->actingAs($sales)->get("/orders/{$order->id}/job-order")
            ->assertOk()
            ->assertSee(route('tasks.file.view', $file), false)
            ->assertSee('IMPRINT');
    }

    public function test_the_panel_says_what_belongs_there_when_it_is_empty(): void
    {
        [$sales, , $order] = $this->shop();

        $this->actingAs($sales)->get("/orders/{$order->id}/job-order")
            ->assertOk()
            ->assertSee('Front flat')
            ->assertSee('Sample');
    }
}
