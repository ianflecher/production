<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Inquiry;
use App\Models\InquiryDesign;
use App\Models\ProductionOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The job order number fills itself in, and says what it will be.
 *
 * store() has always set aside whatever is typed in that box when the brief
 * already has an open job: one brief, one job number, because the floor runs
 * its designs as one job. The FORM went on offering the next free number
 * anyway — so writing the second design of a brief showed IC2026-01235 while
 * the save was going to write IC2026-01233, and the officer retyped the
 * sibling's number by hand every time to settle an argument that was never
 * going to happen.
 *
 * Nothing about what gets saved changes here. What changes is that the box
 * stops disagreeing with it.
 */
class TheJobNumberFillsItselfInTest extends TestCase
{
    use RefreshDatabase;

    private function officer(): User
    {
        return User::factory()->create(['job_role' => User::ROLE_SALES, 'is_active' => true]);
    }

    /** A brief with an approved design, ready for its job order to be written. */
    private function brief(User $officer): Inquiry
    {
        $client = Client::create([
            'name' => 'Consignee', 'last_name' => 'Co',
            'contact_number' => '0917-000-0000', 'created_by' => $officer->id,
        ]);

        $inquiry = Inquiry::create([
            'client_id' => $client->id,
            'what_they_want' => 'Three shirts',
            'created_by' => $officer->id,
            'layout_sent_at' => now()->subDay(),
        ]);

        foreach (range(0, 2) as $i) {
            InquiryDesign::create([
                'inquiry_id' => $inquiry->id,
                'position' => $i,
                'label' => 'DESIGN '.($i + 1),
                'status' => InquiryDesign::STATUS_APPROVED,
            ]);
        }

        return $inquiry->fresh();
    }

    private function orderOn(Inquiry $inquiry, string $number, User $officer): ProductionOrder
    {
        return ProductionOrder::create([
            'order_number' => $number,
            'customer_name' => 'Consignee Co',
            'client_id' => $inquiry->client_id,
            'inquiry_id' => $inquiry->id,
            'inquiry_design_id' => $inquiry->designs->first()->id,
            'product_type' => 'round_neck',
            'quantity' => 10,
            'due_date' => now()->addWeeks(2),
            'created_by' => $officer->id,
            'status' => 'active',
        ]);
    }

    /* ---------------- the sequence itself ---------------- */

    /** The highest used, plus one — not a count, which a cancelled job breaks. */
    public function test_the_next_number_follows_the_highest_one_used(): void
    {
        $officer = $this->officer();
        $inquiry = $this->brief($officer);

        $this->orderOn($inquiry, 'IC'.now()->format('Y').'-01234', $officer);

        $this->assertSame('IC'.now()->format('Y').'-01235', ProductionOrder::nextOrderNumber());
    }

    /* ---------------- a brief that already has a job ---------------- */

    /**
     * The bug. The form offered a brand-new number for the second design of a
     * brief that already had one.
     */
    public function test_the_second_design_is_offered_the_number_the_brief_already_has(): void
    {
        $officer = $this->officer();
        $inquiry = $this->brief($officer);
        $number = 'IC'.now()->format('Y').'-01233';

        $this->orderOn($inquiry, $number, $officer);

        $this->actingAs($officer)
            ->get(route('orders.create', ['inquiry' => $inquiry->id]))
            ->assertOk()
            ->assertViewHas('nextNumber', $number)
            ->assertViewHas('numberIsInherited', true);
    }

    /** And the box does not invite typing that will be ignored. */
    public function test_the_box_is_read_only_once_the_brief_has_a_number(): void
    {
        $officer = $this->officer();
        $inquiry = $this->brief($officer);
        $number = 'IC'.now()->format('Y').'-01233';

        $this->orderOn($inquiry, $number, $officer);

        $html = $this->actingAs($officer)
            ->get(route('orders.create', ['inquiry' => $inquiry->id]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('readonly', $html);
        $this->assertStringContainsString('every design on it shares one', $html);
    }

    /* ---------------- the first one on a brief ---------------- */

    public function test_the_first_design_is_offered_the_next_free_number(): void
    {
        $officer = $this->officer();
        $inquiry = $this->brief($officer);

        $this->actingAs($officer)
            ->get(route('orders.create', ['inquiry' => $inquiry->id]))
            ->assertOk()
            ->assertViewHas('nextNumber', ProductionOrder::nextOrderNumber())
            ->assertViewHas('numberIsInherited', false);
    }

    /* ---------------- moving one design out ---------------- */

    /**
     * A design moved onto its own number must not take the brief's default
     * with it.
     *
     * Splitting is done on the order's own Edit page, not here - writing a
     * new one always joins the brief, which is what OneInquiryOneJobOrderNumberTest
     * protects. But once a design HAS been moved out, openJobFor() decides what
     * the next one is offered, and it used to take the first row: renumber the
     * earliest of three and its new number became the brief's default, while
     * the two that stayed kept the old one. It follows the majority now.
     */
    public function test_renumbering_the_earliest_design_does_not_move_the_default(): void
    {
        $officer = $this->officer();
        $inquiry = $this->brief($officer);
        $group = 'IC'.now()->format('Y').'-01233';

        $first = $this->orderOn($inquiry, $group, $officer);
        $this->orderOn($inquiry, $group, $officer);
        $this->orderOn($inquiry, $group, $officer);

        $first->update(['order_number' => 'IC'.now()->format('Y').'-09090']);

        $this->assertSame($group, ProductionOrder::openJobFor($inquiry->id)->order_number,
            "the design that left took the brief's number with it");

        $this->actingAs($officer)
            ->get(route('orders.create', ['inquiry' => $inquiry->id]))
            ->assertOk()
            ->assertViewHas('nextNumber', $group);
    }

    /** With no majority to follow, the earliest still wins - the old answer. */
    public function test_a_tie_goes_to_the_earliest(): void
    {
        $officer = $this->officer();
        $inquiry = $this->brief($officer);
        $first = 'IC'.now()->format('Y').'-01233';

        $this->orderOn($inquiry, $first, $officer);
        $this->orderOn($inquiry, 'IC'.now()->format('Y').'-09090', $officer);

        $this->assertSame($first, ProductionOrder::openJobFor($inquiry->id)->order_number);
    }

    /**
     * A delivered or cancelled job is finished with, so a brief that comes
     * back gets a number of its own rather than joining a closed one — the
     * same rule openJobFor() has always used.
     */
    public function test_a_finished_job_does_not_lend_its_number(): void
    {
        $officer = $this->officer();
        $inquiry = $this->brief($officer);

        $this->orderOn($inquiry, 'IC'.now()->format('Y').'-01233', $officer)
            ->update(['status' => 'cancelled']);

        $this->actingAs($officer)
            ->get(route('orders.create', ['inquiry' => $inquiry->id]))
            ->assertOk()
            ->assertViewHas('numberIsInherited', false);
    }
}
