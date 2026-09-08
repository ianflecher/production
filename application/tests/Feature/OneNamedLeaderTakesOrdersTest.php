<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Inquiry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The order desk is the account officers', plus one named person.
 *
 * Taking a job belongs to the account officers. Carla runs the floor and takes
 * jobs, and neither answer to that was true: moving her to the account officer
 * role would take away the approvals she runs the floor with, and opening the
 * desk to the leader role would hand it to the supervisors as well.
 *
 * So it is granted to the person. These tests hold that line from both ends -
 * she can, and the leader beside her still cannot.
 */
class OneNamedLeaderTakesOrdersTest extends TestCase
{
    use RefreshDatabase;

    private function leader(bool $mayTakeOrders = false): User
    {
        return User::factory()->create([
            'job_role' => 'leader',
            'is_active' => true,
            'can_create_orders' => $mayTakeOrders,
        ]);
    }

    /** Page one, where a job is taken: the enquiry that holds the client. */
    private function inquiryFor(User $who): Inquiry
    {
        $client = Client::create([
            'name' => 'Walk-in', 'last_name' => 'Client',
            'contact_number' => '0917-555-0101', 'created_by' => $who->id,
        ]);

        return Inquiry::create([
            'client_id' => $client->id,
            'created_by' => $who->id,
            'status' => Inquiry::STATUS_OPEN,
        ]);
    }

    public function test_a_leader_who_was_granted_it_can_open_the_order_form(): void
    {
        $carla = $this->leader(mayTakeOrders: true);

        $this->assertTrue($carla->canCreateOrders());

        $this->actingAs($carla)->get(route('inquiries.create'))->assertOk();

        // The order form is page two, reached through the enquiry that holds
        // the client - the same road an account officer takes.
        $this->actingAs($carla)
            ->get(route('orders.create', ['inquiry' => $this->inquiryFor($carla)->id]))
            ->assertOk();
    }

    public function test_the_grant_does_not_open_the_desk_to_every_leader(): void
    {
        // The whole point of hanging it off the person: the supervisors and the
        // other leaders are still out.
        $otherLeader = $this->leader();

        $this->assertFalse($otherLeader->canCreateOrders());

        $this->actingAs($otherLeader)->get(route('orders.create'))->assertForbidden();  // refused before the redirect
        $this->actingAs($otherLeader)->get(route('inquiries.create'))->assertForbidden();
    }

    public function test_a_supervisor_reads_as_a_leader_and_is_still_out(): void
    {
        $supervisor = User::factory()->create([
            'job_role' => 'supervisor', 'is_active' => true,
        ]);

        $this->assertSame(User::ROLE_LEADER, $supervisor->role);
        $this->assertFalse($supervisor->canCreateOrders());
        $this->actingAs($supervisor)->get(route('orders.create'))->assertForbidden();
    }

    public function test_the_account_officers_own_desk_is_untouched(): void
    {
        $officer = User::factory()->create(['job_role' => User::ROLE_SALES, 'is_active' => true]);

        $this->assertTrue($officer->canCreateOrders());
        $this->actingAs($officer)
            ->get(route('orders.create', ['inquiry' => $this->inquiryFor($officer)->id]))
            ->assertOk();
    }

    public function test_she_can_actually_take_a_job_not_just_open_the_form(): void
    {
        $carla = $this->leader(mayTakeOrders: true);

        $inquiry = $this->inquiryFor($carla);

        $this->actingAs($carla)->post(route('orders.store'), [
            'inquiry_id' => $inquiry->id,
            'order_number' => 'IC2026-CARLA1',
            'due_date' => now()->addWeeks(3)->toDateString(),
            'product_type' => 'round_neck',
            'sizes' => ['M' => 10, 'L' => 5],
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('production_orders', [
            'order_number' => 'IC2026-CARLA1',
            'created_by' => $carla->id,
        ]);
    }

    public function test_she_keeps_the_approvals_she_runs_the_floor_with(): void
    {
        // The reason this is a grant and not a change of role.
        $carla = $this->leader(mayTakeOrders: true);

        $this->assertTrue($carla->isLeader());
    }
}
