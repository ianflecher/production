<?php

namespace Tests\Feature;

use App\Models\JobOrder;
use App\Models\ProductionOrder;
use App\Models\SewingOperation;
use App\Models\StationSession;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The sewing sheet reaches the person holding the garment.
 *
 * What a polo takes — twenty operations and a standard allowing minute against
 * each — has always been written down. It was written down in a spreadsheet,
 * which is to say it was kept everywhere except the one place it is needed: at
 * the machine, by somebody who has just picked up a garment and has to know
 * what is left to do to it.
 *
 * So it is on the page where the sewer writes down what they did, a line of it
 * goes into that record with one press, and a line that is missing can be
 * written down there and then rather than waiting for somebody with a database.
 */
class TheSewingSheetIsAtTheMachineTest extends TestCase
{
    use RefreshDatabase;

    private function sewer(): User
    {
        return User::factory()->create([
            'name' => 'Marites Bautista', 'job_role' => 'Sewing', 'is_active' => true,
        ]);
    }

    /** What the last runningAtSewing() left behind, for a second page to use. */
    private ?ProductionOrder $order = null;

    private ?StationSession $session = null;

    /** An order sitting at sewing, and a sewer running it. */
    private function runningAtSewing(User $who): StationSession
    {
        $sales = User::factory()->create(['job_role' => User::ROLE_SALES, 'is_active' => true]);

        $order = ProductionOrder::create([
            'order_number' => 'IC2026-0'.random_int(1000, 9999),
            'customer_name' => 'Seam Co',
            'product_type' => 'round_neck',
            'quantity' => 20,
            'due_date' => now()->addWeek(),
            'created_by' => $sales->id,
            'status' => 'active',
        ]);

        $order->jobOrder()->create(['status' => 'draft', 'created_by' => $sales->id]);

        Task::create([
            'production_order_id' => $order->id,
            'department' => 'Sewing',
            'sequence' => 12,
            'stage' => 6,
            'status' => 'ready',
        ]);

        $this->actingAs($who)->post('/stations/start', [
            'station' => 'sewing_1',
            'operator_name' => $who->name,
            'production_order_id' => $order->fresh()->id,
        ]);

        $this->order = $order->fresh();

        return $this->session = StationSession::where('station', 'sewing_1')
            ->whereNull('ended_at')->firstOrFail();
    }

    /* ---------------- the sheet itself ---------------- */

    public function test_the_shops_sheet_came_in_whole(): void
    {
        $this->assertCount(18, SewingOperation::garments());
        $this->assertSame(336, SewingOperation::count());

        // Every garment has work under it — an empty heading is a bad import.
        foreach (SewingOperation::sheet() as $garment => $operations) {
            $this->assertGreaterThan(0, $operations->count(), $garment.' came in with no operations');
        }
    }

    /** A line read straight off the shop's own sheet. */
    public function test_a_line_reads_the_way_the_shop_wrote_it(): void
    {
        $tee = SewingOperation::forGarment('REGULAR T-SHIRT');

        $this->assertSame('NECK BOND / JOIN SHOULDER', $tee->first()->name);
        $this->assertSame('1.763', $tee->first()->samLabel());
        $this->assertSame('FLATBED BIAS CUTTING PER ROLL', $tee->last()->name);
    }

    /**
     * Four operations on SHORT were never timed. A blank cell means nobody
     * knows, not nought, and a nought would go into the day's total as free.
     */
    public function test_an_untimed_operation_is_blank_and_not_zero(): void
    {
        $seaming = SewingOperation::forGarment('SHORT')->firstWhere('name', 'SEAMING F&B');

        $this->assertNotNull($seaming);
        $this->assertNull($seaming->sam);
        $this->assertSame('—', $seaming->samLabel());

        $this->assertSame(4, SewingOperation::forGarment('SHORT')
            ->filter(fn ($o) => $o->sam === null)->count());
    }

    /**
     * The same operation twice on one garment is the sheet, not a mistake:
     * a windbreaker jacket carries two WOVEN & TAGS lines at different minutes.
     */
    public function test_a_garment_may_carry_the_same_operation_twice(): void
    {
        $woven = SewingOperation::forGarment('WINDBREAKER JACKET')
            ->where('name', 'WOVEN & TAGS')
            ->pluck('sam')
            ->map(fn ($s) => (float) $s)
            ->all();

        $this->assertEqualsWithDelta([1.152, 0.8076], $woven, 0.0001);
    }

    /* ---------------- it is where the work is ---------------- */

    public function test_a_sewing_station_lays_the_record_out_by_product(): void
    {
        $sewer = $this->sewer();
        $session = $this->runningAtSewing($sewer);

        $html = $this->actingAs($sewer)
            ->get(route('stations.finish', $session))
            ->assertOk()->getContent();

        $this->assertStringContainsString('What this garment takes, and who did it', $html);
        $this->assertStringContainsString('WINDBREAKER (BOA)', $html, 'the picker is missing a product');
        $this->assertStringContainsString('NECK BOND / JOIN SHOULDER', $html);

        // The operation and who did it, and nothing else: no minutes, no
        // pieces-a-day. That is what the floor asked for.
        $this->assertStringNotContainsString('PCS/DAY', $html);
        $this->assertStringNotContainsString('1.763', $html, 'the minutes are back on the shop floor page');

        // Every operation is a line, and every line has a box for a name.
        $this->assertStringContainsString('sheet[sewing_log][0][work]', $html);
        $this->assertStringContainsString('sheet[sewing_log][0][name]', $html);
        $this->assertStringContainsString('sheet[sewing_garment]', $html);
    }

    /**
     * "What they did" has pointed at dl_sheet_work since the log replaced the
     * seam boxes, and nothing has ever defined that list — so the box offered
     * nothing at all. The sheet fills it.
     */
    public function test_the_what_they_did_box_offers_the_shops_operations(): void
    {
        $sewer = $this->sewer();
        $session = $this->runningAtSewing($sewer);

        $html = $this->actingAs($sewer)
            ->get(route('stations.finish', $session))->getContent();

        $this->assertStringContainsString('<datalist id="dl_sheet_work">', $html);

        $list = Str::between($html, '<datalist id="dl_sheet_work">', '</datalist>');

        $this->assertStringContainsString('ATTACH VEST HOLDER', $list);
        $this->assertStringContainsString('NECK BOND / JOIN SHOULDER', $list);
    }

    /** A printer has no use for a seam list, and does not get one. */
    public function test_a_printing_station_gets_no_sewing_sheet(): void
    {
        $printer = User::factory()->create(['job_role' => 'printer', 'is_active' => true]);
        $sewer = $this->sewer();
        $session = $this->runningAtSewing($sewer);

        // The same page, asked of a station that does not sew.
        $session->update(['station' => 'printer_atexco']);

        $this->assertStringNotContainsString(
            'What this garment takes',
            $this->actingAs($printer)->get(route('stations.finish', $session))->getContent()
        );
    }

    /**
     * And in the SEWING block of the correction sheet, which is the same
     * record in the shop's own yellow boxes. A line typed against the wrong
     * garment is put right there, and the sheet has to be there to put it
     * right against.
     */
    public function test_the_correction_sheet_has_it_in_its_sewing_block(): void
    {
        $sewer = $this->sewer();
        $this->runningAtSewing($sewer);

        $html = $this->actingAs($sewer)
            ->get(route('orders.sheet', $this->order))
            ->assertOk()->getContent();

        $this->assertStringContainsString('What this garment takes, and who did it', $html);
        $this->assertStringContainsString('sheet[sewing_log][0][name]', $html);

        // In the sewing block, above the record that prints — not in a card
        // of its own somewhere further up the page. strpos() returning false
        // would read as position 0 and pass on its own, so both are checked.
        $picker = strpos($html, 'What this garment takes, and who did it');
        $printed = strpos($html, 'Notes from sewer');

        $this->assertNotFalse($picker);
        $this->assertNotFalse($printed);
        $this->assertLessThan($printed, $picker, 'the sheet is not in the sewing block');
    }

    /**
     * It sits inside the job order sheet, which is one big form. Its own forms
     * are pushed past the end of it and its inputs point back by id, because a
     * browser throws away a form nested in another one — and with it the
     * button that adds a line.
     */
    public function test_nothing_it_draws_is_a_form_inside_a_form(): void
    {
        $sewer = $this->sewer();
        $this->runningAtSewing($sewer);

        foreach (['orders.sheet' => $this->order, 'stations.finish' => $this->session] as $route => $on) {
            $html = $this->actingAs($sewer)->get(route($route, $on))->getContent();

            $this->assertStringContainsString('form="soAddSheet"', $html, $route.' has no add boxes');
            $this->assertStringContainsString('id="soAddSheet"', $html, $route.' has no add form');

            $previous = libxml_use_internal_errors(true);
            $dom = new \DOMDocument;
            $dom->loadHTML('<?xml encoding="utf-8" ?>'.$html);
            libxml_clear_errors();
            libxml_use_internal_errors($previous);

            foreach ($dom->getElementsByTagName('form') as $form) {
                $this->assertSame(0, $form->getElementsByTagName('form')->length,
                    $route.' nests a form inside the form at '.$form->getAttribute('action'));
            }
        }
    }

    /** A garment can be asked for by name, so the link is worth sending. */
    public function test_the_link_can_name_the_garment(): void
    {
        $sewer = $this->sewer();
        $session = $this->runningAtSewing($sewer);

        $this->actingAs($sewer)
            ->get(route('stations.finish', $session).'?garment=evo+vest')
            ->assertOk()
            ->assertSee('value="EVO VEST" selected', false);
    }

    /* ---------------- and it grows ---------------- */

    public function test_a_sewer_adds_a_line_and_it_lands_at_the_end(): void
    {
        $sewer = $this->sewer();

        $this->actingAs($sewer)->post(route('sewing-operations.store'), [
            'garment' => 'SANDO',
            'name' => 'attach chest pocket',
            'sam' => 1.25,
        ])->assertSessionHasNoErrors();

        $sando = SewingOperation::forGarment('SANDO');

        $this->assertSame(10, $sando->count());
        $this->assertSame('attach chest pocket', $sando->last()->name);
        $this->assertSame('1.25', $sando->last()->samLabel());
        $this->assertSame('Marites Bautista', $sando->last()->added_by);
    }

    /** Typed at a machine, it lands on the garment that is already there. */
    public function test_a_garment_typed_any_which_way_is_the_same_garment(): void
    {
        $this->actingAs($this->sewer())->post(route('sewing-operations.store'), [
            'garment' => '  polo   shirt ',
            'name' => 'TACK THE HEM',
        ]);

        $this->assertCount(18, SewingOperation::garments(), 'a second POLO SHIRT was made');
        $this->assertSame(21, SewingOperation::forGarment('POLO SHIRT')->count());
    }

    /** A garment the shop starts making on a Tuesday can be written on Tuesday. */
    public function test_a_new_garment_can_be_started(): void
    {
        $this->actingAs($this->sewer())->post(route('sewing-operations.store'), [
            'garment' => 'Cycling Bib',
            'name' => 'ATTACH CHAMOIS',
            'sam' => 2.5,
        ])->assertSessionHasNoErrors();

        $this->assertContains('CYCLING BIB', SewingOperation::garments());
    }

    /** Blank minutes are an answer. Zero minutes is not. */
    public function test_the_minutes_may_be_blank_but_not_nothing(): void
    {
        $sewer = $this->sewer();

        $this->actingAs($sewer)->post(route('sewing-operations.store'), [
            'garment' => 'SANDO', 'name' => 'NOT TIMED YET', 'sam' => null,
        ])->assertSessionHasNoErrors();

        $this->assertNull(SewingOperation::forGarment('SANDO')->last()->sam);

        $this->actingAs($sewer)->post(route('sewing-operations.store'), [
            'garment' => 'SANDO', 'name' => 'TAKES NO TIME AT ALL', 'sam' => 0,
        ])->assertSessionHasErrors('sam');
    }

    public function test_a_line_can_be_taken_back_off(): void
    {
        $sewer = $this->sewer();
        $wrong = SewingOperation::create([
            'garment' => 'SANDO', 'name' => 'TYPED BY MISTAKE', 'position' => 99,
        ]);

        $this->actingAs($sewer)
            ->delete(route('sewing-operations.destroy', $wrong))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('sewing_operations', ['id' => $wrong->id]);
    }

    /* ---------------- who may write on it ---------------- */

    public function test_the_sewing_line_and_its_leaders_may_write_on_it(): void
    {
        foreach (['Sewing', User::JOB_SEWING_SUPERVISOR, User::JOB_SUPERVISOR, 'production'] as $role) {
            $this->assertTrue(
                User::factory()->make(['job_role' => $role, 'is_active' => true])->canEditSewingSheet(),
                $role.' cannot write on the sewing sheet'
            );
        }

        $this->assertTrue(
            User::factory()->make(['job_role' => User::ROLE_SUPER_ADMIN])->canEditSewingSheet()
        );
    }

    /** Everyone else reads it. An artist does not write on it. */
    public function test_somebody_who_works_no_machine_cannot_write_on_it(): void
    {
        $artist = User::factory()->create(['job_role' => User::JOB_ARTIST, 'is_active' => true]);

        $this->assertFalse($artist->fresh()->canEditSewingSheet());

        $this->actingAs($artist)->post(route('sewing-operations.store'), [
            'garment' => 'SANDO', 'name' => 'SOMETHING',
        ])->assertForbidden();

        $this->assertSame(9, SewingOperation::forGarment('SANDO')->count());
    }

    /* ---------------- a line per operation, a name per line ---------------- */

    /**
     * The record is the garment's own list now. Pick the product and every
     * operation it takes is a line with a box beside it, instead of five blank
     * slots and a memory of what a polo takes.
     */
    public function test_the_record_is_a_line_for_every_operation_of_the_garment(): void
    {
        $sewer = $this->sewer();
        $session = $this->runningAtSewing($sewer);

        $html = $this->actingAs($sewer)
            ->get(route('stations.finish', $session).'?garment=SANDO')
            ->assertOk()->getContent();

        // SANDO takes nine, and the record has nine lines plus the spares.
        foreach (range(0, 8 + JobOrder::SEWING_LOG_SPARES - 1) as $i) {
            $this->assertStringContainsString('sheet[sewing_log]['.$i.'][name]', $html, 'line '.$i.' is missing');
        }

        $this->assertStringNotContainsString('sheet[sewing_log][12][name]', $html, 'the record runs on past the garment');
        $this->assertStringContainsString('ATTACH RIBBINGS ARMHOLE', $html);
    }

    /** And the names go down against the operations they were typed beside. */
    public function test_the_names_are_saved_against_their_operations(): void
    {
        $sewer = $this->sewer();
        $session = $this->runningAtSewing($sewer);

        $this->actingAs($sewer)->post(route('stations.end', $session), [
            'end_reason' => 'done',
            'sheet' => [
                'sewing_garment' => 'SANDO',
                'sewing_log' => [
                    ['work' => 'NECK BOND / JOIN SHOULDER', 'listed' => '1', 'name' => 'Melanie'],
                    ['work' => 'FLATBED', 'listed' => '1', 'name' => ''],
                    ['work' => 'TAPPING NECK', 'listed' => '1', 'name' => 'PERLA'],
                    ['work' => 'Unpicked a seam', 'name' => 'Arlene'],
                    ['work' => '', 'name' => ''],
                ],
            ],
        ])->assertSessionHasNoErrors();

        $jo = $this->order->fresh()->jobOrder;

        // Three lines: the two somebody signed, and the one they typed. The
        // operation nobody did is not recorded as done.
        $this->assertSame([
            ['work' => 'NECK BOND / JOIN SHOULDER', 'name' => 'Melanie'],
            ['work' => 'TAPPING NECK', 'name' => 'PERLA'],
            ['work' => 'Unpicked a seam', 'name' => 'Arlene'],
        ], $jo->sewing_log);

        $this->assertSame('SANDO', $jo->sewing_garment);
    }

    /** And whoever opens the job next gets the same list, without picking. */
    public function test_the_job_remembers_which_product_it_is(): void
    {
        $sewer = $this->sewer();
        $session = $this->runningAtSewing($sewer);

        $this->order->jobOrder->update(['sewing_garment' => 'EVO VEST']);

        $this->actingAs($sewer)
            ->get(route('stations.finish', $session))
            ->assertOk()
            ->assertSee('value="EVO VEST" selected', false)
            ->assertSee('ATTACH VEST HOLDER');
    }

    /**
     * A name already written comes back beside its own operation, wherever the
     * shop has since moved that operation in the list.
     */
    public function test_what_was_written_comes_back_on_its_own_line(): void
    {
        $sewer = $this->sewer();
        $session = $this->runningAtSewing($sewer);

        $this->order->jobOrder->update([
            'sewing_garment' => 'SANDO',
            'sewing_log' => [['work' => 'TAPPING NECK', 'name' => 'Jovy']],
        ]);

        $rows = $this->order->fresh()->jobOrder->sewingRows(
            SewingOperation::forGarment('SANDO')
                ->map(fn ($o) => ['id' => $o->id, 'name' => $o->name])->all()
        );

        $tapping = collect($rows)->firstWhere('work', 'TAPPING NECK');

        $this->assertSame('Jovy', $tapping['name']);
        $this->assertTrue($tapping['listed']);

        // And nobody else was given her name.
        $this->assertSame(1, collect($rows)->where('name', 'Jovy')->count());
    }

    /**
     * Work the list has not got is kept on its own line rather than thrown
     * away when the garment changes under it.
     */
    public function test_work_the_list_has_not_got_keeps_its_line(): void
    {
        $sewer = $this->sewer();
        $this->runningAtSewing($sewer);

        $this->order->jobOrder->update([
            'sewing_log' => [['work' => 'Re-ran the whole hem', 'name' => 'Leonor']],
        ]);

        $rows = $this->order->fresh()->jobOrder->sewingRows(
            SewingOperation::forGarment('SANDO')
                ->map(fn ($o) => ['id' => $o->id, 'name' => $o->name])->all()
        );

        $mine = collect($rows)->firstWhere('work', 'Re-ran the whole hem');

        $this->assertNotNull($mine, 'a line nobody could match was dropped');
        $this->assertSame('Leonor', $mine['name']);
        $this->assertFalse($mine['listed'], 'a typed line must stay editable');
    }

    /* ---------------- and no Blade leaking ---------------- */

    /**
     * Blade does not compile a directive written flush against a word, which
     * is how "Manage stock levels@if (...)" printed itself onto a live page.
     */
    public function test_the_page_prints_no_directives(): void
    {
        $sewer = $this->sewer();
        $html = $this->actingAs($sewer)
            ->get(route('stations.finish', $this->runningAtSewing($sewer)))->getContent();

        $this->assertStringNotContainsString('@if (', $html);
        $this->assertStringNotContainsString('@endif', $html);
        $this->assertStringNotContainsString('@foreach', $html);
    }
}
