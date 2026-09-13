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
 * A client who ordered and came back is still somebody to chase.
 *
 * The follow-up list asked for one of two things: an inquiry still marked open,
 * or an APPROVED design with no order written for it. Between those two sits
 * the client who has ordered before and asked for something else — Stephanie
 * Moto, four shirts under one job number, then a fifth design added to the same
 * brief. Her inquiry is marked ordered, so the first half does not catch her,
 * and the new design was still on the artist's desk, so the second did not
 * either. She fell off the list entirely, and the officer who has to write that
 * fifth order had nowhere to see her until the client approved the drawing.
 *
 * So the second half now counts a design that has not become a job yet at
 * whatever stage it has reached. On the shop's own data this added exactly one
 * name: hers.
 */
class AReturningClientStaysOnTheFollowUpListTest extends TestCase
{
    use RefreshDatabase;

    private function officer(): User
    {
        return User::factory()->create(['job_role' => User::ROLE_SALES, 'is_active' => true]);
    }

    /** A brief that has already produced a job, the way hers had. */
    private function briefAlreadyOrdered(User $officer): Inquiry
    {
        $client = Client::create(['name' => 'Stephanie', 'last_name' => 'Moto']);

        $inquiry = Inquiry::create([
            'client_id' => $client->id,
            'created_by' => $officer->id,
            'status' => Inquiry::STATUS_OPEN,
            'what_they_want' => 'Shirts',
            'layout_sent_at' => now(),
        ]);

        $first = $inquiry->designs()->create([
            'label' => 'SHIRT 1', 'position' => 0,
            'artist_id' => User::factory()->create(['job_role' => User::JOB_ARTIST])->id,
            'status' => InquiryDesign::STATUS_APPROVED,
        ]);

        $order = ProductionOrder::create([
            'order_number' => 'IC2026-BACK1',
            'client_id' => $client->id,
            'customer_name' => 'Stephanie Moto',
            'product_type' => 'round_neck',
            'quantity' => 10,
            'due_date' => now()->addWeeks(2),
            'status' => 'active',
            'created_by' => $officer->id,
            'inquiry_id' => $inquiry->id,
            'inquiry_design_id' => $first->id,
        ]);

        $inquiry->markOrdered($order);

        return $inquiry->fresh();
    }

    private function onTheList(Inquiry $inquiry): bool
    {
        return Inquiry::forFollowUp()->whereKey($inquiry->id)->exists();
    }

    /** Everything she asked for has been written up: nothing to chase. */
    public function test_a_finished_brief_is_off_the_list(): void
    {
        $inquiry = $this->briefAlreadyOrdered($this->officer());

        $this->assertSame(Inquiry::STATUS_ORDERED, $inquiry->status);
        $this->assertFalse($this->onTheList($inquiry));
    }

    public function test_a_new_design_on_it_brings_her_back_while_it_is_still_being_drawn(): void
    {
        $officer = $this->officer();
        $inquiry = $this->briefAlreadyOrdered($officer);

        $inquiry->designs()->create([
            'label' => 'SHIRT 3', 'position' => 1,
            'artist_id' => User::factory()->create(['job_role' => User::JOB_ARTIST])->id,
            'status' => InquiryDesign::STATUS_WITH_ARTIST,
            'sent_at' => now(),
        ]);

        $this->assertTrue($this->onTheList($inquiry->fresh()),
            'the officer who has to write that order had nowhere to see her');
    }

    /** Handed back and waiting on the client counts too. */
    public function test_a_design_waiting_on_the_client_counts(): void
    {
        $officer = $this->officer();
        $inquiry = $this->briefAlreadyOrdered($officer);

        $inquiry->designs()->create([
            'label' => 'SHIRT 3', 'position' => 1,
            'artist_id' => User::factory()->create(['job_role' => User::JOB_ARTIST])->id,
            'status' => InquiryDesign::STATUS_SUBMITTED,
        ]);

        $this->assertTrue($this->onTheList($inquiry->fresh()));
    }

    /** And she leaves again the one way anybody leaves: by ordering. */
    public function test_writing_the_order_takes_her_off_again(): void
    {
        $officer = $this->officer();
        $inquiry = $this->briefAlreadyOrdered($officer);

        $second = $inquiry->designs()->create([
            'label' => 'SHIRT 3', 'position' => 1,
            'artist_id' => User::factory()->create(['job_role' => User::JOB_ARTIST])->id,
            'status' => InquiryDesign::STATUS_APPROVED,
        ]);

        $this->assertTrue($this->onTheList($inquiry->fresh()));

        ProductionOrder::create([
            'order_number' => 'IC2026-BACK1',   // the same job number, by the same rule
            'client_id' => $inquiry->client_id,
            'customer_name' => 'Stephanie Moto',
            'product_type' => 'round_neck',
            'quantity' => 10,
            'due_date' => now()->addWeeks(2),
            'status' => 'active',
            'created_by' => $officer->id,
            'inquiry_id' => $inquiry->id,
            'inquiry_design_id' => $second->id,
        ]);

        $this->assertFalse($this->onTheList($inquiry->fresh()),
            'she stayed on the list after the order was written');
    }

    /**
     * A design still being written up is nobody's work yet — it has not been
     * given to an artist, so there is nothing to chase about it.
     */
    public function test_a_design_still_being_written_up_does_not_count(): void
    {
        $officer = $this->officer();
        $inquiry = $this->briefAlreadyOrdered($officer);

        $inquiry->designs()->create([
            'label' => 'SHIRT 3', 'position' => 1,
            'status' => 'brief',
        ]);

        $this->assertFalse($this->onTheList($inquiry->fresh()));
    }

    /** The page the officer actually reads, not just the query behind it. */
    public function test_she_is_named_on_the_page(): void
    {
        $officer = $this->officer();
        $inquiry = $this->briefAlreadyOrdered($officer);

        $inquiry->designs()->create([
            'label' => 'SHIRT 3', 'position' => 1,
            'artist_id' => User::factory()->create(['job_role' => User::JOB_ARTIST])->id,
            'status' => InquiryDesign::STATUS_WITH_ARTIST,
        ]);

        $this->actingAs($officer)->get(route('inquiries.index'))
            ->assertOk()
            ->assertSee('Stephanie Moto');
    }
}
