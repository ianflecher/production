<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Inquiry;
use App\Models\InquiryDesign;
use App\Models\ProductionOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * A name on the follow-up list says what is outstanding on it.
 *
 * The card carried the client, where they are from, their number and what
 * they asked for. On a brief whose products ARE its designs there is usually
 * nothing typed in "what they asked for" - the designs are the answer - so
 * the row came out as a name and a phone number. Nothing said why the name
 * was still on the list, and a brief with four approved designs nobody had
 * written up looked exactly like one with none.
 *
 * Stephanie Moto on the live board is the case: eight designs, four of them
 * approved with no order against them, and an empty "what they want".
 *
 * The rule shown is the same one that puts the row on the list at all
 * (scopeForFollowUp): out of the officer's hands and not yet a job. Anything
 * else would be a second opinion about why a name is there.
 */
class TheFollowUpCardSaysWhatIsOutstandingTest extends TestCase
{
    use RefreshDatabase;

    private function officer(): User
    {
        return User::factory()->create(['job_role' => User::ROLE_SALES, 'is_active' => true]);
    }

    private function brief(User $officer, string $wants = ''): Inquiry
    {
        $client = Client::create([
            'name' => 'Stephanie', 'last_name' => 'Moto', 'contact_number' => '0917-000-0000',
            'created_by' => $officer->id,
        ]);

        return Inquiry::create([
            'client_id' => $client->id,
            'what_they_want' => $wants,
            'created_by' => $officer->id,
        ]);
    }

    private function design(Inquiry $inquiry, string $status, int $position = 0): InquiryDesign
    {
        return InquiryDesign::create([
            'inquiry_id' => $inquiry->id,
            'position' => $position,
            'status' => $status,
        ]);
    }

    /* ---------------- what counts ---------------- */

    public function test_an_approved_design_with_no_order_is_outstanding(): void
    {
        $inquiry = $this->brief($this->officer());
        $this->design($inquiry, InquiryDesign::STATUS_APPROVED);

        $this->assertCount(1, $inquiry->fresh()->designsHoldingTheFollowUp());
    }

    /** Written up as a job, and the brief is that much closer to answered. */
    public function test_a_design_that_became_an_order_is_not_outstanding(): void
    {
        $officer = $this->officer();
        $inquiry = $this->brief($officer);
        $design = $this->design($inquiry, InquiryDesign::STATUS_APPROVED);

        ProductionOrder::create([
            'order_number' => 'IC2026-07777',
            'customer_name' => 'Stephanie Moto',
            'product_type' => 'round_neck',
            'quantity' => 20,
            'due_date' => now()->addWeeks(2),
            'created_by' => $officer->id,
            'status' => 'active',
            'inquiry_id' => $inquiry->id,
            'inquiry_design_id' => $design->id,
        ]);

        $this->assertCount(0, $inquiry->fresh()->designsHoldingTheFollowUp());
    }

    /**
     * A design still being written up IS outstanding here — the opposite of
     * what the artist's queue and the design badge say about the same row,
     * and all three are right. Those ask whether it is on the artist's desk.
     * This asks whether the office is finished with the client, and a
     * half-written design is the officer's own work still to do.
     */
    public function test_a_design_still_in_the_brief_is_outstanding(): void
    {
        $inquiry = $this->brief($this->officer());
        $this->design($inquiry, InquiryDesign::STATUS_BRIEF);

        $this->assertCount(1, $inquiry->fresh()->designsHoldingTheFollowUp());
    }

    /** And it is named as the officer's own, not lumped in with the rest. */
    public function test_the_card_names_the_officers_own_unfinished_brief(): void
    {
        $officer = $this->officer();
        $inquiry = $this->brief($officer);
        $this->design($inquiry, InquiryDesign::STATUS_BRIEF);

        $this->actingAs($officer)
            ->get(route('inquiries.index'))
            ->assertOk()
            ->assertSee('1 not sent yet');
    }

    public function test_being_drawn_and_with_the_client_both_count(): void
    {
        $inquiry = $this->brief($this->officer());
        $this->design($inquiry, InquiryDesign::STATUS_WITH_ARTIST, 0);
        $this->design($inquiry, InquiryDesign::STATUS_SUBMITTED, 1);

        $this->assertCount(2, $inquiry->fresh()->designsHoldingTheFollowUp());
    }

    /* ---------------- what the card reads ---------------- */

    /** The live shape: four approved, nothing written up, no ask text. */
    public function test_the_card_counts_the_approved_designs_waiting_for_an_order(): void
    {
        $officer = $this->officer();
        $inquiry = $this->brief($officer);

        foreach (range(0, 3) as $i) {
            $this->design($inquiry, InquiryDesign::STATUS_APPROVED, $i);
        }

        $this->actingAs($officer)
            ->get(route('inquiries.index'))
            ->assertOk()
            ->assertSee('Stephanie Moto')
            ->assertSee('4 approved')
            ->assertSee('need orders');
    }

    /** One reads as one, not "1 approved — need orders". */
    public function test_a_single_approved_design_is_said_in_the_singular(): void
    {
        $officer = $this->officer();
        $inquiry = $this->brief($officer);
        $this->design($inquiry, InquiryDesign::STATUS_APPROVED);

        $this->actingAs($officer)
            ->get(route('inquiries.index'))
            ->assertOk()
            ->assertSee('1 approved')
            ->assertSee('needs an order')
            ->assertDontSee('need orders');
    }

    public function test_the_card_separates_the_three_states(): void
    {
        $officer = $this->officer();
        $inquiry = $this->brief($officer);
        $this->design($inquiry, InquiryDesign::STATUS_APPROVED, 0);
        $this->design($inquiry, InquiryDesign::STATUS_SUBMITTED, 1);
        $this->design($inquiry, InquiryDesign::STATUS_WITH_ARTIST, 2);
        $this->design($inquiry, InquiryDesign::STATUS_WITH_ARTIST, 3);

        $this->actingAs($officer)
            ->get(route('inquiries.index'))
            ->assertOk()
            ->assertSee('1 approved')
            ->assertSee('1 with the client')
            ->assertSee('2 being drawn');
    }

    /** A brief with no designs at all says nothing rather than four zeroes. */
    public function test_a_brief_with_no_designs_shows_no_pills(): void
    {
        $officer = $this->officer();
        $this->brief($officer, 'Acid wash shirt');

        $this->actingAs($officer)
            ->get(route('inquiries.index'))
            ->assertOk()
            ->assertSee('Acid wash shirt')
            ->assertDontSee('being drawn')
            ->assertDontSee('not sent yet')
            ->assertDontSee('approved —', false);
    }

    /* ---------------- and it stays one page of queries ---------------- */

    /**
     * The page costs the same whether the list is short or long.
     *
     * This is asked once per row, so the relations have to be loaded with the
     * list. A threshold would not catch it - six rows cost 17 queries instead
     * of 12 without the eager load, and any round number big enough to be
     * safe is big enough to miss that. What actually matters is that the
     * count does not GROW with the number of names, so that is what is asked.
     */
    public function test_the_list_costs_the_same_however_many_names_are_on_it(): void
    {
        $officer = $this->officer();

        $load = function () use ($officer) {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->actingAs($officer)->get(route('inquiries.index'))->assertOk();
            $count = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $count;
        };

        foreach (range(1, 6) as $n) {
            $this->design($this->brief($officer), InquiryDesign::STATUS_APPROVED);
        }

        $six = $load();

        foreach (range(1, 6) as $n) {
            $this->design($this->brief($officer), InquiryDesign::STATUS_APPROVED);
        }

        $twelve = $load();

        $this->assertSame($six, $twelve,
            'the follow-up list asks the database per row: '.$six.' queries for six names, '
            .$twelve.' for twelve');
    }
}
