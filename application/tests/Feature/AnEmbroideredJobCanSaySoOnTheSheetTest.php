<?php

namespace Tests\Feature;

use App\Models\JobOrder;
use App\Models\ProductionOrder;
use App\Models\User;
use App\Services\Stations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * An embroidered job can name embroidery as what makes it.
 *
 * The tech pack's Printer box offered five printers and nothing else. An
 * embroidered job has no printer, and that box is one of the seventeen that
 * must be answered before a pack can be submitted — so the account officer's
 * only options were to leave it blank and be refused, or name a machine that
 * is not making this job. Picking "Embroidery" as the print type actually
 * defaulted the printer to the Sticker Printer, which is how a sheet ends up
 * telling the floor the wrong machine.
 *
 * IC2026-00009 is the live one: product type "Embro Print Only", print_type
 * and printer both null, the pack unsent for four days.
 *
 * The press dropdowns have accepted embroidery for the same reason all along
 * — "the client sometimes wants embroidery instead of a press" — so this is
 * that reasoning said about the printer.
 *
 * The trap, and the reason this is NOT simply a sixth entry in PRINTERS:
 * that constant builds the station board. Stations::all() turns every entry
 * into a printer_<key> tile in the Printing group running the Printer and
 * Mass production departments. The shop already HAS an Embroidery station,
 * in Add-ons, running the Embroidery department. A second one is not a
 * machine anybody owns, and it would appear on the printer operators' board.
 */
class AnEmbroideredJobCanSaySoOnTheSheetTest extends TestCase
{
    use RefreshDatabase;

    /* ---------------- the box ---------------- */

    public function test_the_printer_box_offers_embroidery(): void
    {
        $this->assertArrayHasKey('embroidery', JobOrder::printerOptions());
        $this->assertSame('Embroidery', JobOrder::printerOptions()['embroidery']);
    }

    /** The real printers are all still there and still first. */
    public function test_the_real_printers_are_untouched(): void
    {
        $options = JobOrder::printerOptions();

        foreach (JobOrder::PRINTERS as $key => $label) {
            $this->assertSame($label, $options[$key]);
        }

        $this->assertCount(count(JobOrder::PRINTERS) + 1, $options);
    }

    public function test_it_reads_back_as_embroidery_on_the_sheet(): void
    {
        $jo = new JobOrder(['printer' => 'embroidery']);

        $this->assertSame('Embroidery', $jo->printerLabel());
    }

    /* ---------------- the trap ---------------- */

    /**
     * The one that would have gone wrong quietly. PRINTERS is the machine
     * list the station board is built from; embroidery is a valid ANSWER on
     * the sheet, not a printer standing on the floor.
     */
    public function test_embroidery_is_not_a_printer_on_the_station_board(): void
    {
        $this->assertArrayNotHasKey('embroidery', JobOrder::PRINTERS);
        $this->assertArrayNotHasKey('printer_embroidery', Stations::all());
    }

    /** And the shop still has exactly one Embroidery station, where it was. */
    public function test_there_is_still_one_embroidery_station(): void
    {
        $embroidery = collect(Stations::all())
            ->filter(fn ($s, $k) => str_contains(strtolower($s['label']), 'embroider'));

        $this->assertCount(1, $embroidery);
        $this->assertSame('Add-ons', $embroidery->first()['group']);
        $this->assertSame(['Embroidery'], $embroidery->first()['departments']);
    }

    /* ---------------- what picking embroidery does ---------------- */

    /**
     * It used to hand the job to the Sticker Printer. Nobody decided that —
     * it was the nearest thing in a list that had no right answer.
     */
    public function test_the_embroidery_print_type_no_longer_defaults_to_the_sticker_printer(): void
    {
        $config = JobOrder::PRINT_TYPES['embroidery'];

        $this->assertSame('embroidery', $config['printer']);
        $this->assertNotSame('manual', $config['printer']);
    }

    /* ---------------- and it can actually be saved ---------------- */

    public function test_the_officer_can_save_embroidery_as_the_printer(): void
    {
        $officer = User::factory()->create(['job_role' => User::ROLE_SALES, 'is_active' => true]);

        $order = ProductionOrder::create([
            'order_number' => 'IC2026-09099',
            'customer_name' => 'Embro Client',
            'product_type' => 'Embro Print Only',
            'quantity' => 111,
            'due_date' => now()->addWeeks(2),
            'created_by' => $officer->id,
            'status' => 'active',
        ]);

        $order->jobOrder()->create(['status' => 'draft', 'created_by' => $officer->id]);

        $this->actingAs($officer)
            ->post(route('job-orders.update', $order), [
                'print_type' => 'embroidery',
                'printer' => 'embroidery',
                'fabric' => 'Cotton blend',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('embroidery', $order->fresh()->jobOrder->printer);
    }

    /**
     * And that is enough to send it. The box being unanswerable was the
     * thing standing between an embroidered job and its artist.
     */
    public function test_an_embroidered_job_is_ready_to_send(): void
    {
        $jo = new JobOrder([
            'print_type' => 'embroidery',
            'printer' => 'embroidery',
            'fabric' => 'Cotton blend',
        ]);

        $this->assertTrue($jo->isReadyToSend());
    }
}
