<?php

namespace Tests\Feature;

use App\Models\ProductionOrder;
use App\Models\User;
use App\Services\StaffAssigner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The artist leader works the bench like the artists he leads, checks the tech
 * packs they hand in, and manages their accounts — and nothing beyond that.
 */
class ArtistLeaderTest extends TestCase
{
    use RefreshDatabase;

    private function lead(): User
    {
        return User::factory()->create(['job_role' => User::JOB_ARTIST_LEAD, 'is_active' => true]);
    }

    private function order(User $sales): ProductionOrder
    {
        $order = ProductionOrder::create([
            'order_number' => 'IC2026-LEAD', 'customer_name' => 'Lead Co',
            'product_type' => 'round_neck', 'quantity' => 10,
            'due_date' => now()->addWeeks(2), 'unit_price' => 350,
            'created_by' => $sales->id, 'status' => 'active',
        ]);
        $order->items()->create(['size' => 'M', 'quantity' => 10]);
        $order->refresh()->buildPipeline([], 'manual');

        return $order->refresh();
    }

    public function test_he_works_the_bench_like_an_artist(): void
    {
        $lead = $this->lead();

        $this->assertTrue($lead->isArtist());
        $this->assertFalse($lead->isLeader());
        $this->assertSame(User::ROLE_AGENT, $lead->role);

        $this->actingAs($lead)->get(route('tasks.mine'))->assertOk();
    }

    public function test_he_takes_tech_packs_off_the_same_rotation_as_the_artists(): void
    {
        $lead = $this->lead();
        $lead->attendances()->create(['date' => now()->toDateString(), 'status' => 'present']);

        $this->assertSame($lead->id, StaffAssigner::next(User::JOB_ARTIST)?->id);
    }

    public function test_he_reaches_the_checking_queue(): void
    {
        $this->actingAs($this->lead())->get(route('approvals'))->assertOk();
    }

    public function test_the_users_page_shows_him_the_artists_only(): void
    {
        User::factory()->create(['job_role' => User::JOB_ARTIST, 'name' => 'An Artist']);
        User::factory()->create(['job_role' => 'sewing', 'name' => 'A Sewer']);
        User::factory()->create(['job_role' => User::ROLE_SALES, 'name' => 'An Officer']);

        $this->actingAs($this->lead())
            ->get(route('users.index'))
            ->assertOk()
            ->assertSee('An Artist')
            ->assertDontSee('A Sewer')
            ->assertDontSee('An Officer');
    }

    public function test_he_marks_an_artist_present_but_nobody_else(): void
    {
        $lead = $this->lead();
        $artist = User::factory()->create(['job_role' => User::JOB_ARTIST]);
        $sewer = User::factory()->create(['job_role' => 'sewing']);

        $this->actingAs($lead)
            ->post(route('users.attendance', $artist), ['status' => 'present'])
            ->assertRedirect();

        $this->actingAs($lead)
            ->post(route('users.attendance', $sewer), ['status' => 'present'])
            ->assertForbidden();
    }

    public function test_he_cannot_hire_reset_or_deactivate_accounts(): void
    {
        $lead = $this->lead();
        $artist = User::factory()->create(['job_role' => User::JOB_ARTIST]);

        $this->actingAs($lead)->post(route('users.reset', $artist))->assertForbidden();
        $this->actingAs($lead)->post(route('users.toggle', $artist))->assertForbidden();
        $this->actingAs($lead)->post(route('users.store'), [])->assertForbidden();
    }

    public function test_the_rest_of_the_leader_pages_are_not_his(): void
    {
        $lead = $this->lead();

        $this->actingAs($lead)->get(route('orders.index'))->assertForbidden();
        $this->actingAs($lead)->get(route('calendar'))->assertForbidden();
        $this->actingAs($lead)->get(route('stations.index'))->assertForbidden();
    }

    public function test_he_does_not_check_the_pack_he_drew_himself(): void
    {
        $lead = $this->lead();
        $sales = User::factory()->create(['job_role' => User::ROLE_SALES]);
        $order = $this->order($sales);

        $pack = $order->tasks()
            ->where('stage', ProductionOrder::STAGE_MOCKUP)
            ->where('approver_role', 'leader')
            ->get();

        foreach ($pack as $t) {
            $t->update(['assigned_to' => $lead->id, 'status' => 'for_checking', 'submitted_at' => now()]);
        }

        $this->actingAs($lead)->get(route('approvals'))
            ->assertOk()
            ->assertDontSee($order->order_number);

        $this->actingAs($lead)->post(route('tasks.approve-package', $order))->assertForbidden();
        $this->assertSame('for_checking', $pack->first()->fresh()->status);
    }

    public function test_he_cannot_sign_off_a_step_that_is_not_the_tech_pack(): void
    {
        $sales = User::factory()->create(['job_role' => User::ROLE_SALES]);
        $order = $this->order($sales);

        $task = $order->tasks()
            ->where('stage', '!=', ProductionOrder::STAGE_MOCKUP)
            ->where('approver_role', 'leader')
            ->firstOrFail();

        $task->update(['status' => 'for_checking', 'submitted_at' => now()]);

        $this->actingAs($this->lead())->post(route('tasks.approve', $task))->assertForbidden();
        $this->assertSame('for_checking', $task->fresh()->status);
    }
}
