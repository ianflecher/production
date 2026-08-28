<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Inquiry;
use App\Models\ProductionOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Somebody asked, and has not ordered yet.
 *
 * The point of the inquiry is that a person who does not order today is still
 * a name the shop can call. So the two things that matter are: taking the
 * details creates the record before any job exists, and the only way off the
 * follow-up list is ordering or being closed with a reason.
 */
class InquiryFollowUpTest extends TestCase
{
    use RefreshDatabase;

    private function officer(?string $team = null, bool $leads = false): User
    {
        return User::factory()->create([
            'job_role' => User::ROLE_SALES,
            'team' => $team,
            'is_team_leader' => $leads,
            'is_active' => true,
        ]);
    }

    private function inquiryOf(User $officer, string $name = 'Asked'): Inquiry
    {
        return Inquiry::create([
            'client_id' => Client::create(['name' => $name, 'last_name' => 'Client'])->id,
            'created_by' => $officer->id,
            'team' => $officer->team,
            'status' => Inquiry::STATUS_OPEN,
        ]);
    }

    public function test_taking_the_details_saves_the_client_before_any_order_exists(): void
    {
        $officer = $this->officer('meta');

        $this->actingAs($officer)->post(route('inquiries.store'), [
            'client_name' => 'Juan',
            'client_last_name' => 'Dela Cruz',
            'client_contact' => '0917-555-1234',
            'client_address' => 'Cebu City',
            'what_they_want' => '30 jerseys, asking for a price',
        ])->assertRedirect();

        $inquiry = Inquiry::firstOrFail();

        $this->assertSame('Juan Dela Cruz', $inquiry->client->fullName());
        $this->assertSame('meta', $inquiry->team);
        $this->assertTrue($inquiry->isOpen());
        $this->assertSame(0, ProductionOrder::count(), 'no order should exist yet');
    }

    public function test_it_stays_on_the_follow_up_list_until_they_order(): void
    {
        $officer = $this->officer();
        $inquiry = $this->inquiryOf($officer);

        // Named on the dashboard, and worked on the Follow-ups tab.
        $this->actingAs($officer)->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Asked Client', false);

        $this->actingAs($officer)->get(route('inquiries.index'))
            ->assertOk()
            ->assertSee('Asked Client', false);

        // The order is written against that inquiry — the only way off.
        $this->actingAs($officer)->post(route('orders.store'), [
            'inquiry_id' => $inquiry->id,
            'order_number' => 'IC2026-Q001',
            'product_type' => 'round_neck',
            'quantity' => 10,
            'sizes' => ['M' => 10],
            'due_date' => now()->addWeeks(3)->toDateString(),
        ])->assertSessionHasNoErrors();

        $inquiry->refresh();

        $this->assertSame(Inquiry::STATUS_ORDERED, $inquiry->status);
        $this->assertNotNull($inquiry->production_order_id);
        $this->assertSame(0, Inquiry::forFollowUp()->count(), 'ordering is what takes them off the list');
    }

    public function test_a_follow_up_is_logged_with_who_made_the_call(): void
    {
        $officer = $this->officer();
        $inquiry = $this->inquiryOf($officer);
        $this->actingAs($officer)->post(route('inquiries.follow-up', $inquiry), [
            'note' => 'Still deciding on the colour',
        ])->assertSessionHasNoErrors();

        $log = $inquiry->followUps()->firstOrFail();

        $this->assertSame('Still deciding on the colour', $log->note);
        $this->assertSame($officer->id, $log->user_id);
    }

    public function test_the_longest_waiting_is_the_one_that_sorts_first(): void
    {
        $officer = $this->officer();

        $first = $this->inquiryOf($officer, 'Older');
        $first->update(['created_at' => now()->subWeek()]);

        $this->inquiryOf($officer, 'Newer');

        $this->assertSame($first->id, Inquiry::forFollowUp()->first()->id);
    }

    public function test_an_officer_sees_only_their_own(): void
    {
        $mine = $this->officer('meta');
        $theirs = $this->officer('meta');

        $this->inquiryOf($mine, 'Mine');
        $this->inquiryOf($theirs, 'Theirs');

        $this->actingAs($mine)->get(route('inquiries.index'))
            ->assertOk()
            ->assertSee('Mine Client', false)
            ->assertDontSee('Theirs Client', false);
    }

    public function test_a_team_leader_sees_the_whole_team_and_can_chase_for_a_member(): void
    {
        $kyson = $this->officer('meta', leads: true);
        $member = $this->officer('meta');
        $otherTeam = $this->officer('vip');

        $memberInquiry = $this->inquiryOf($member, 'Member');
        $this->inquiryOf($otherTeam, 'Vip');

        $this->actingAs($kyson)->get(route('inquiries.index'))
            ->assertOk()
            ->assertSee('Member Client', false)
            ->assertDontSee('Vip Client', false);

        // Chasing a member's client is the whole of what leading a team means.
        $this->actingAs($kyson)->post(route('inquiries.follow-up', $memberInquiry), [
            'note' => 'Called for Member, they want a sample',
        ])->assertSessionHasNoErrors();

        $this->assertSame($kyson->id, $memberInquiry->followUps()->firstOrFail()->user_id,
            'the follow-up is logged against whoever actually made the call');
    }

    public function test_a_team_leader_cannot_touch_another_teams_inquiry(): void
    {
        $kyson = $this->officer('meta', leads: true);
        $vipInquiry = $this->inquiryOf($this->officer('vip'));

        $this->actingAs($kyson)->post(route('inquiries.follow-up', $vipInquiry), [
            'note' => 'not mine to chase',
        ])->assertForbidden();
    }

    public function test_an_ordinary_officer_does_not_lead_a_team(): void
    {
        $this->assertFalse($this->officer('meta')->leadsTeam());
        $this->assertTrue($this->officer('meta', leads: true)->leadsTeam());

        // A leader flag with no team is not leadership of anything.
        $this->assertFalse($this->officer(null, leads: true)->leadsTeam());
    }

    public function test_an_order_written_without_one_still_records_who_asked(): void
    {
        $officer = $this->officer();

        $this->actingAs($officer)->post(route('orders.store'), [
            'order_number' => 'IC2026-Q002',
            'client_name' => 'Walk',
            'client_last_name' => 'In',
            'client_contact' => '0917-000-0000',
            'client_address' => 'Cebu',
            'product_type' => 'round_neck',
            'quantity' => 10,
            'sizes' => ['M' => 10],
            'due_date' => now()->addWeeks(3)->toDateString(),
        ])->assertSessionHasNoErrors();

        $inquiry = Inquiry::firstOrFail();

        $this->assertSame(Inquiry::STATUS_ORDERED, $inquiry->status);
        $this->assertSame('Walk In', $inquiry->client->fullName());
    }
}
