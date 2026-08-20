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
            'sequence' => 2, 'stage' => 2, 'status' => 'ready',
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
            ->assertSee('Straight cut')
            ->assertSee('Ordinary')
            ->assertSee('IC sticker');
    }

    public function test_the_banner_names_the_print_method_and_the_garment(): void
    {
        // "STANDARD DTF PLACING FOR SHIRT" — built rather than retyped on every
        // sheet, so it cannot say DTF on a sublimation job.
        [$sales, , $order] = $this->shop();

        $this->actingAs($sales)->get("/orders/{$order->id}/job-order")
            ->assertOk()
            ->assertSee('STANDARD DTF PLACING FOR ROUND NECK / V-NECK SHIRT');
    }

    public function test_the_artist_fills_their_half_in_the_pack_itself(): void
    {
        [, $artist, $order, $task] = $this->shop();

        $this->actingAs($artist)->get("/my-tasks/{$task->id}/job-order")
            ->assertOk()
            ->assertSee('name="design_name"', false)
            ->assertSee('name="colorways"', false)
            ->assertSee('name="placements[0][label]"', false)
            // …around the pack, not instead of it.
            ->assertSee('tp-sheet', false);
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
            'design_name' => 'Aerox Lifestyle — White',
            'fitting' => 'Original fit',
            'thread_color' => 'Black',
            'colorways' => 'Black, White, Accent',
            'placements' => [
                ['label' => 'Back', 'width' => '14.0', 'height' => '10.688'],
                ['label' => 'Left chest', 'width' => '4.0', 'height' => '2.313'],
            ],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->actingAs($sales)->get("/orders/{$order->id}/job-order")
            ->assertOk()
            ->assertSee('Aerox Lifestyle — White')
            ->assertSee('Original fit')
            ->assertSee('BACK')
            ->assertSee('14.0')
            ->assertSee('10.688');
    }

    public function test_an_empty_placement_row_is_not_a_placement(): void
    {
        // The form always offers a spare row, so most saves carry one.
        [, $artist, $order, $task] = $this->shop();

        $this->actingAs($artist)->post("/my-tasks/{$task->id}/tech-pack", [
            'placements' => [
                ['label' => 'Back', 'width' => '14.0', 'height' => '10.688'],
                ['label' => '', 'width' => '', 'height' => ''],
            ],
        ]);

        $this->assertCount(1, $order->fresh()->jobOrder->print_placements);
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

        $first = $order->fresh()->jobOrder->folder_shot_path;
        $this->assertNotNull($first);
        Storage::disk('local')->assertExists($first);

        $this->actingAs($artist)->post("/my-tasks/{$task->id}/tech-pack", [
            'folder_shot' => UploadedFile::fake()->image('folder-again.png'),
        ]);

        // The old picture is of a folder that has since changed, which is the
        // reason for uploading a new one.
        Storage::disk('local')->assertMissing($first);
        $this->assertSame('folder-again.png', $order->fresh()->jobOrder->folder_shot_name);
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

    public function test_the_seam_record_is_still_there_under_the_pack(): void
    {
        // The pack is the spec; who sewed which seam is what the shop wrote
        // down afterwards. Needed, but not part of the spec — and losing it
        // would take the floor's own record with it.
        [$sales, , $order] = $this->shop();

        $this->actingAs($sales)->get("/orders/{$order->id}/job-order")
            ->assertOk()
            ->assertSee('Production record');
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
            ->assertSee('No print placements recorded yet');
    }

    public function test_the_template_panel_carries_the_artists_template(): void
    {
        // The flats the floor cuts and prints to — a different thing from the
        // mockup, which is what the client approved.
        [$sales, $artist, $order] = $this->shop();

        $template = Task::create([
            'production_order_id' => $order->id, 'department' => 'Production template',
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
            ->assertSee('SAMPLE');
    }

    public function test_the_panel_says_what_belongs_there_when_it_is_empty(): void
    {
        [$sales, , $order] = $this->shop();

        $this->actingAs($sales)->get("/orders/{$order->id}/job-order")
            ->assertOk()
            ->assertSee('Template goes here')
            ->assertSee('Production template step');
    }
}
