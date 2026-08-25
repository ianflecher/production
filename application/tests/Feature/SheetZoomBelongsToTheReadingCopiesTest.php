<?php

namespace Tests\Feature;

use App\Models\ProductionOrder;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The sheet can be made bigger to read, but only where it is being read.
 *
 * Everything on the pack is measured against the sheet's own width, so the
 * control scales it rather than resizing it — the layout is untouched and only
 * drawn larger, which is what keeps the pins and captions where somebody put
 * them.
 *
 * That trick does not survive editing. A scaled sheet puts every drag and every
 * pin measurement out by the scale factor, and both are done by hand against
 * what is on screen. So the control belongs to the copies nobody is filling in,
 * and this is the test that says so — the scaling itself is the browser's job
 * and was checked by driving one.
 */
class SheetZoomBelongsToTheReadingCopiesTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: User, 1: User, 2: ProductionOrder, 3: Task} */
    private function shop(): array
    {
        $sales = User::factory()->create(['job_role' => User::ROLE_SALES, 'is_active' => true]);
        $artist = User::factory()->create(['job_role' => User::JOB_ARTIST, 'is_active' => true]);

        $order = ProductionOrder::create([
            'order_number' => 'IC2026-ZOOM', 'customer_name' => 'Zoom Co',
            'product_type' => 'round_neck', 'quantity' => 25,
            'due_date' => now()->addWeeks(2), 'created_by' => $sales->id, 'status' => 'active',
        ]);

        $order->jobOrder()->create([
            'status' => 'sent_to_artist', 'created_by' => $sales->id,
            'print_type' => 'dtf', 'printer' => 'dtf_printer', 'fabric' => 'Cotton blend',
        ]);

        $order->techPack()->create(['design_name' => 'Zoom Tee']);

        $task = Task::create([
            'production_order_id' => $order->id, 'department' => 'Final mockup',
            'sequence' => 2, 'stage' => 2, 'status' => 'complete', 'approved_at' => now(),
            'team' => User::JOB_ARTIST, 'assigned_to' => $artist->id,
        ]);

        return [$sales, $artist, $order->refresh(), $task];
    }

    public function test_the_sheet_the_office_reads_can_be_made_bigger(): void
    {
        [$sales, , $order] = $this->shop();

        $this->actingAs($sales)->get("/orders/{$order->id}/job-order")
            ->assertOk()
            ->assertSee('data-tp-scale', false)
            ->assertSee('data-tp-scale-reset', false);
    }

    public function test_the_floor_can_make_it_bigger_at_the_station(): void
    {
        // The reason it exists: a materials list read across a workbench.
        [, , $order] = $this->shop();
        $sewer = User::factory()->create(['job_role' => 'Sewing', 'is_active' => true]);

        $this->actingAs($sewer)->get("/orders/{$order->id}/package")
            ->assertOk()
            ->assertSee('data-tp-scale', false);
    }

    public function test_the_artist_filling_the_pack_does_not_get_it(): void
    {
        // Their pins and their dragged captions are placed against what is on
        // screen. Scale the sheet under them and every measurement is out.
        [, $artist, $order] = $this->shop();

        $pack = Task::create([
            'production_order_id' => $order->id, 'department' => 'Tech pack',
            'sequence' => 3, 'stage' => 2, 'status' => 'ready',
            'team' => User::JOB_ARTIST, 'assigned_to' => $artist->id,
        ]);

        $this->actingAs($artist)->get("/my-tasks/{$pack->id}/job-order")
            ->assertOk()
            ->assertDontSee('data-tp-scale', false);
    }

    public function test_the_officer_filling_their_half_does_not_get_it_either(): void
    {
        [$sales, , $order] = $this->shop();

        $this->actingAs($sales)->get("/job-orders/{$order->id}/edit")
            ->assertOk()
            ->assertDontSee('data-tp-scale', false);
    }

    public function test_the_control_never_reaches_paper(): void
    {
        // It is a screen control, not part of the sheet.
        [$sales, , $order] = $this->shop();

        $html = $this->actingAs($sales)->get("/orders/{$order->id}/job-order")
            ->assertOk()
            ->getContent();

        $at = strpos($html, 'data-tp-scale');
        $opening = strrpos(substr($html, 0, $at), '<div');

        $this->assertStringContainsString(
            'no-print',
            substr($html, $opening, $at - $opening + 40),
            'the zoom control would print on the sheet'
        );
    }
}
