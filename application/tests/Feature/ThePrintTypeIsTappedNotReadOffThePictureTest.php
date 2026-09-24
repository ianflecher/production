<?php

namespace Tests\Feature;

use App\Models\JobOrder;
use App\Models\ProductionOrder;
use App\Models\TechPack;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * An imported Tech Pack asks which print type it is. It does not guess.
 *
 * It used to try to read one off the picture, and it never once succeeded on
 * the shop's own sheets — because the words on them say nothing about which
 * option was chosen:
 *
 *   the cap template prints FULL SUBLI, CUT AND SEW, DTF, SILKSCREEN and EMBRO
 *   and TICKS ONE IN RED, so the text matches three types at once and the
 *   detector, which only accepts exactly one, returns nothing every time;
 *
 *   the shirt template has no print-type wording at all — only PRINTER: ATEXCO;
 *
 *   a third has neither.
 *
 * The officer is looking at the sheet. Tapping the answer is quicker than a
 * minute of recognition that says "no clear print type found" either way.
 *
 * And the print LOCATION does give it away, which nothing had noticed: the shop
 * files work by the machine that runs it. Every job on the system agrees.
 */
class ThePrintTypeIsTappedNotReadOffThePictureTest extends TestCase
{
    use RefreshDatabase;

    /* ---------------- the location tells us ---------------- */

    public function test_the_print_location_gives_the_print_type_away(): void
    {
        foreach ([
            '\\\\IC-EMBRO\\Users\\Public\\`FOR EMBRO\\MICK\\BOA\\BOA CAP' => 'Embroidery',
            '\\\\ic-printdpt-tan\\FOR PRINT - 2025\\NEW ATEXCO 2026\\9.SEPT 2026' => 'Full Sublimation',
            '\\\\ic-printdpt-tan\\FOR PRINT - 2025\\DTF PC\\`2026\\MICK' => 'DTF',
            '\\\\Ic-printdpt-tan\\for print - 2025\\NEW ATEXCO 2026' => 'Full Sublimation',
        ] as $path => $expected) {
            $this->assertSame($expected, JobOrder::printTypeFromLocation($path), $path);
        }
    }

    /** A path that names no machine is not guessed at. */
    public function test_a_path_that_says_nothing_suggests_nothing(): void
    {
        $this->assertNull(JobOrder::printTypeFromLocation('\\\\server\\jobs\\2026\\september'));
        $this->assertNull(JobOrder::printTypeFromLocation(''));
        $this->assertNull(JobOrder::printTypeFromLocation(null));
    }

    /* ---------------- and the page asks ---------------- */

    private function importedPack(?string $location, ?string $printType = null): ProductionOrder
    {
        $officer = User::factory()->create(['job_role' => User::ROLE_SALES, 'is_active' => true]);

        $order = ProductionOrder::create([
            'order_number' => 'IC2026-PT'.random_int(1000, 9999),
            'customer_name' => 'Picture Co',
            'product_type' => 'round_neck',
            'quantity' => 20,
            'due_date' => now()->addWeeks(2),
            'created_by' => $officer->id,
            'status' => 'active',
        ]);

        $order->jobOrder()->create([
            'status' => 'sent_to_artist', 'created_by' => $officer->id, 'print_type' => $printType,
        ]);

        TechPack::create([
            'production_order_id' => $order->id,
            'imported_pack_path' => 'imported-tech-packs/sheet.jpg',
            'imported_pack_name' => 'sheet.jpg',
            'file_location_notes' => $location,
        ]);

        return $order->fresh();
    }

    private function page(ProductionOrder $order): string
    {
        // The Tech Pack's own page, where the imported picture is shown and
        // the officer answers what it says.
        return $this->actingAs(User::find($order->created_by))
            ->get(route('job-orders.edit', $order))
            ->assertOk()->getContent();
    }

    /** The shop's own words, as things to tap. */
    public function test_the_options_are_offered_as_taps(): void
    {
        $html = $this->page($this->importedPack('\\\\IC-EMBRO\\Users\\Public\\`FOR EMBRO\\MICK'));

        foreach (['Full Sublimation', 'DTF', 'Silkscreen', 'Embroidery'] as $choice) {
            $this->assertStringContainsString('data-print-type="'.$choice.'"', $html, $choice.' cannot be tapped');
        }

        $this->assertStringContainsString('name="print_type"', $html);
    }

    /** With the box empty, the location's answer is offered. */
    public function test_an_empty_box_is_told_what_the_location_says(): void
    {
        $html = $this->page($this->importedPack('\\\\IC-EMBRO\\Users\\Public\\`FOR EMBRO\\MICK'));

        $this->assertStringContainsString('which is <strong>Embroidery</strong>', $html);
    }

    /** It is a suggestion. Nothing is filled in on the officer's behalf. */
    public function test_the_suggestion_is_not_silently_saved(): void
    {
        $order = $this->importedPack('\\\\IC-EMBRO\\Users\\Public\\`FOR EMBRO\\MICK');

        $this->page($order);

        $this->assertNull($order->fresh()->jobOrder->print_type,
            'the page filled the print type in by itself');
    }

    /** The button that could never work is gone, and so is its engine. */
    public function test_the_image_reader_is_gone(): void
    {
        $html = $this->page($this->importedPack('\\\\IC-EMBRO\\Users\\Public\\`FOR EMBRO\\MICK'));

        $this->assertStringNotContainsString('Read print type from image', $html);
        $this->assertStringNotContainsString('tech-pack-ocr.js', $html);
        $this->assertStringNotContainsString('No clear print type found', $html);
    }

    /** An answer already given is shown as the one that is chosen. */
    public function test_an_answer_already_given_is_marked(): void
    {
        $html = $this->page($this->importedPack(
            '\\\\ic-printdpt-tan\\FOR PRINT - 2025\\NEW ATEXCO 2026', 'Full Sublimation'
        ));

        $this->assertStringContainsString('value="Full Sublimation"', $html);
        // and it stops nagging about the location once somebody has answered
        $this->assertStringNotContainsString('which is <strong>', $html);
    }
}
