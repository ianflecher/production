<?php

namespace Tests\Feature;

use App\Models\JobOrder;
use App\Models\ProductionOrder;
use App\Models\Task;
use App\Models\TechPack;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The pack is one landscape page, and the marks on it stay on the paper.
 *
 * A callout's place is two numbers, each a share of the sheet's WIDTH — which
 * is what gets saved, and what the artist dragged. Across the sheet that is
 * right. Down it, it only holds while the sheet keeps one shape, and it does
 * not: on screen the sheet is about 759 by 804, on A4 landscape roughly 1077
 * by 700. A mark two thirds of the way down on screen is off the bottom of the
 * page in print, and the browser prints empty pages to reach it — four of them,
 * from one dot, on a sheet that otherwise fitted with room to spare.
 *
 * Nothing in the suite could see it: every other test reads the HTML, and the
 * HTML was fine. It took printing the page with a real browser to find, and it
 * survived overflow:hidden, overflow:clip, contain:paint, a fixed sheet height
 * and position:fixed before the cause was understood.
 *
 * So the contract that fixes it is what is checked here: the numbers reach the
 * element as custom properties, and the print stylesheet caps the vertical one
 * against the sheet's own HEIGHT. Neither half is any use alone.
 */
class TechPackPrintsOnePageTest extends TestCase
{
    use RefreshDatabase;

    /** An order whose pack carries a callout low on the sheet. */
    private function packWithALowCallout(): array
    {
        $sales = User::factory()->create(['job_role' => User::ROLE_SALES, 'is_active' => true]);
        $artist = User::factory()->create(['job_role' => User::JOB_ARTIST, 'is_active' => true]);

        $order = ProductionOrder::create([
            'order_number' => 'IC2026-PRINT', 'customer_name' => 'One Page Co',
            'product_type' => 'round_neck', 'quantity' => 20,
            'due_date' => now()->addWeeks(2), 'created_by' => $sales->id, 'status' => 'active',
        ]);

        $order->jobOrder()->create([
            'status' => 'sent_to_artist', 'created_by' => $sales->id,
            'print_type' => 'dtf', 'printer' => 'dtf_printer',
        ]);

        $order->techPack()->create([
            // 90.8 down is about 259mm on a page 198mm tall. This is the exact
            // shape of the fault: a value that is fine on screen and off the
            // paper in print.
            'callouts' => [
                'front_artwork' => ['tx' => 22.49, 'ty' => 90.8, 'fx' => 33.23, 'fy' => 51.1],
            ],
        ]);

        $task = Task::create([
            'production_order_id' => $order->id, 'department' => 'Tech pack',
            'sequence' => 3, 'stage' => 2, 'status' => 'ready',
            'team' => User::JOB_ARTIST, 'assigned_to' => $artist->id,
        ]);

        return [$sales, $artist, $order, $task];
    }

    public function test_a_callout_is_placed_by_custom_property_not_by_top(): void
    {
        [, $artist, , $task] = $this->packWithALowCallout();

        $html = $this->actingAs($artist)
            ->get("/my-tasks/{$task->id}/job-order")
            ->assertOk()
            ->getContent();

        // The numbers are handed over for the stylesheet to interpret…
        $this->assertStringContainsString('--pin-x:22.49', $html);
        $this->assertStringContainsString('--pin-y:90.8', $html);

        // …and never written straight into the box, where nothing can cap them.
        $this->assertStringNotContainsString('top:90.8cqw', $html);
    }

    public function test_the_sheet_the_shop_reads_places_its_marks_the_same_way(): void
    {
        [$sales, , $order] = $this->packWithALowCallout();

        $html = $this->actingAs($sales)
            ->get("/orders/{$order->id}/job-order")
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('--pin-y:90.8', $html);
        $this->assertStringNotContainsString('top:90.8cqw', $html);
    }

    public function test_the_print_stylesheet_caps_a_mark_against_the_sheets_height(): void
    {
        $css = file_get_contents(public_path('css/tech-pack.css'));

        $print = mb_substr($css, mb_strrpos($css, '@media print'));

        // A percentage here is a share of the sheet's HEIGHT, which is the
        // thing a mark going down the page is really measured against.
        $this->assertMatchesRegularExpression(
            '/\.tp-ref-pin[^{]*\{[^}]*top:\s*min\([^)]*var\(--pin-y[^}]*%/s',
            $print,
            'the print sheet no longer caps a callout against its own height'
        );
    }

    public function test_the_sheet_is_placed_from_the_pair_it_is_given(): void
    {
        $css = file_get_contents(public_path('css/tech-pack.css'));

        // Without this the custom properties are just unused numbers.
        $this->assertMatchesRegularExpression(
            '/\.tp-ref-pin\s*\{[^}]*left:\s*calc\(var\(--pin-x/s',
            $css
        );
        $this->assertMatchesRegularExpression(
            '/\.tp-ref-pin\s*\{[^}]*top:\s*calc\(var\(--pin-y/s',
            $css
        );
    }

    public function test_nothing_around_the_sheet_claims_a_page_of_its_own(): void
    {
        // The app shell stands a full viewport tall, and when a browser prints,
        // a viewport IS the page: the shell filled the sheet to the last pixel
        // and the padding around it spilled onto a second, empty one.
        $css = file_get_contents(public_path('css/tech-pack.css'));

        $print = mb_substr($css, mb_strrpos($css, '@media print'));

        $this->assertMatchesRegularExpression(
            '/\.shell[^{]*\{[^}]*min-height:\s*0/s',
            $print,
            'the page furniture has a height of its own again on paper'
        );
    }

    public function test_a_pack_with_no_callouts_still_opens(): void
    {
        [, $artist, $order, $task] = $this->packWithALowCallout();

        $order->techPack->update(['callouts' => null]);

        $this->actingAs($artist)
            ->get("/my-tasks/{$task->id}/job-order")
            ->assertOk()
            ->assertSee('tp-reference-sheet', false);
    }

    /**
     * The height the sheet is held to has to be the height of the paper.
     *
     * It was capped at 186mm, which was A4 landscape inside a 6mm margin. The
     * paper became 8.5 x 11 and the margin went to nothing, and the cap stayed
     * - so every print put the sheet in the top of the page with a white strip
     * along the bottom, and raising the bands inside it did nothing because
     * the cap simply clipped them.
     */
    public function test_the_sheet_is_held_to_the_height_of_the_paper(): void
    {
        $css = file_get_contents(public_path('css/tech-pack.css'));
        $print = mb_substr($css, mb_strrpos($css, '@media print'));

        preg_match('/@page[^{]*\{[^}]*size:\s*11in\s+8\.5in/', $print, $page);
        $this->assertNotEmpty($page, 'the pack should print on 8.5 x 11 landscape');

        preg_match('/\.tp-reference-sheet\s*\{\s*max-height:\s*([\d.]+)mm/', $print, $cap);
        $this->assertNotEmpty($cap, 'the sheet should still be held to one page');

        $capMm = (float) $cap[1];
        $paperMm = 8.5 * 25.4;   // the short side of the sheet, landscape

        // The sheet holds itself off the paper's edge, because a printer grips
        // the sheet there and trims whatever is drawn in it. So the height to
        // check against is the paper less that inset, not the paper.
        preg_match('/\.tp-reference-sheet\s*\{[^}]*margin:\s*([\d.]+)mm\s+([\d.]+)mm/', $print, $edge);
        $this->assertNotEmpty($edge, 'the sheet should hold itself off the edge of the paper');

        // Rounded: 215.9 - 16 lands on 199.89999999999998 in binary, and a cap
        // of exactly 199.9 is not "less than" that by 2e-14.
        $usableMm = round($paperMm - (2 * (float) $edge[1]), 1);

        $this->assertLessThanOrEqual($usableMm, $capMm, 'the cap must not exceed what the paper can print');
        $this->assertGreaterThan($usableMm - 5, $capMm,
            'the cap is more than 5mm short of the printable height, which shows as a white strip');
    }

    public function test_printed_tags_are_large_enough_to_read(): void
    {
        $css = file_get_contents(public_path('css/tech-pack.css'));
        $print = mb_substr($css, mb_strrpos($css, '@media print'));

        // The default a tag prints at, when the artist has not sized it.
        $this->assertMatchesRegularExpression(
            '/\.tp-ref-image-tag[^{]*\{\s*width:\s*30mm\s*!important;\s*height:\s*20mm\s*!important;/s',
            $print
        );
    }

    /**
     * A tag the artist dragged bigger has to print at the size they made it.
     *
     * The size is stored on the element itself, in cqw - a share of the
     * sheet's width, which is what makes a box look the same on paper as on
     * screen. A print rule carrying !important beat that inline size, so every
     * tag came out identical no matter what the artist had done, and raising
     * the fixed size only made them all equally wrong.
     */
    public function test_a_tag_the_artist_sized_keeps_that_size_on_paper(): void
    {
        $css = file_get_contents(public_path('css/tech-pack.css'));
        $print = mb_substr($css, mb_strrpos($css, '@media print'));

        $this->assertMatchesRegularExpression(
            '/\.tp-ref-image-tag:not\(\[style\*="width"\]\)/',
            $print,
            'the fixed print size must stand aside for a size the artist set'
        );
    }
}
