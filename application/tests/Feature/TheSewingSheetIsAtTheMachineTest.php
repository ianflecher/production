<?php

namespace Tests\Feature;

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

        return StationSession::where('station', 'sewing_1')->whereNull('ended_at')->firstOrFail();
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

        $totals = SewingOperation::totals(SewingOperation::forGarment('SHORT'));

        $this->assertSame(4, $totals['untimed']);
        $this->assertEqualsWithDelta(32.934, $totals['minutes'], 0.0001);
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

    public function test_a_sewing_station_shows_the_sheet_with_its_add_buttons(): void
    {
        $sewer = $this->sewer();
        $session = $this->runningAtSewing($sewer);

        $html = $this->actingAs($sewer)
            ->get(route('stations.finish', $session))
            ->assertOk()->getContent();

        $this->assertStringContainsString('What this garment takes', $html);
        $this->assertStringContainsString('WINDBREAKER (BOA)', $html, 'the picker is missing a garment');
        $this->assertStringContainsString('NECK BOND / JOIN SHOULDER', $html);
        // The ADD column, and something for it to drop a line into.
        $this->assertStringContainsString('class="so-use"', $html);
        $this->assertStringContainsString('sheet[sewing_log][0][work]', $html);
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

    /** And a page of its own, for reading it away from a machine. */
    public function test_the_sheet_has_a_page_of_its_own(): void
    {
        $html = $this->actingAs($this->sewer())
            ->get(route('sewing-operations.index'))
            ->assertOk()->getContent();

        $this->assertStringContainsString('HOODY JACKET RAGLAN', $html);
        $this->assertStringContainsString('ATTACH VEST HOLDER', $html);
    }

    /** A garment can be asked for by name, so the link is worth sending. */
    public function test_the_link_can_name_the_garment(): void
    {
        $this->actingAs($this->sewer())
            ->get(route('sewing-operations.index', ['garment' => 'evo vest']))
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

        $this->actingAs($artist)->get(route('sewing-operations.index'))->assertOk();

        $this->actingAs($artist)->post(route('sewing-operations.store'), [
            'garment' => 'SANDO', 'name' => 'SOMETHING',
        ])->assertForbidden();

        $this->assertSame(9, SewingOperation::forGarment('SANDO')->count());
    }

    /* ---------------- and no Blade leaking ---------------- */

    /**
     * Blade does not compile a directive written flush against a word, which
     * is how "Manage stock levels@if (...)" printed itself onto a live page.
     */
    public function test_the_page_prints_no_directives(): void
    {
        $html = $this->actingAs($this->sewer())
            ->get(route('sewing-operations.index'))->getContent();

        $this->assertStringNotContainsString('@if (', $html);
        $this->assertStringNotContainsString('@endif', $html);
        $this->assertStringNotContainsString('@foreach', $html);
    }
}
