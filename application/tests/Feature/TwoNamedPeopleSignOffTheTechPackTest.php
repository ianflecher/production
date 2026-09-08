<?php

namespace Tests\Feature;

use App\Models\ProductionOrder;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The tech pack's final sign-off is two named people.
 *
 * It was open to the leader ROLE, and the supervisors read as leaders in this
 * app - four of them do. They run parts of the floor; none of them is who the
 * shop means when it says the pack has been checked. So it is written down as
 * people: Carla and Rommel.
 *
 * Only the tech pack. Everything else those leaders sign off, they still do.
 */
class TwoNamedPeopleSignOffTheTechPackTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: User, 1: User, 2: ProductionOrder, 3: Task} */
    private function packWaitingOnTheLeader(): array
    {
        $officer = User::factory()->create(['job_role' => User::ROLE_SALES, 'is_active' => true]);
        $artist = User::factory()->create(['job_role' => User::JOB_ARTIST, 'is_active' => true]);

        $order = ProductionOrder::create([
            'order_number' => 'IC2026-SIGN1', 'customer_name' => 'Sign Off',
            'product_type' => 'round_neck', 'quantity' => 20,
            'due_date' => now()->addWeeks(3), 'created_by' => $officer->id, 'status' => 'active',
        ]);

        $order->jobOrder()->create([
            'status' => 'sent_to_artist', 'created_by' => $officer->id,
            'print_type' => 'dtf', 'printer' => 'dtf_printer',
        ]);

        Task::create([
            'production_order_id' => $order->id, 'department' => 'Final mockup',
            'sequence' => 2, 'stage' => 2, 'status' => 'complete', 'approved_at' => now(),
            'team' => User::JOB_ARTIST, 'assigned_to' => $artist->id,
        ]);

        // Past the account officer, waiting on the final sign-off.
        $pack = Task::create([
            'production_order_id' => $order->id, 'department' => 'Tech pack',
            'sequence' => 3, 'stage' => 2, 'status' => 'for_checking',
            'team' => User::JOB_ARTIST, 'assigned_to' => $artist->id,
            'approver_role' => 'leader',
            'submitted_at' => now(),
        ]);

        return [$officer, $artist, $order->fresh(), $pack];
    }

    private function person(string $name, string $jobRole, bool $signsOff = false): User
    {
        return User::factory()->create([
            'name' => $name,
            'job_role' => $jobRole,
            'is_active' => true,
            'can_approve_tech_packs' => $signsOff,
        ]);
    }

    public function test_carla_can_sign_the_tech_pack_off(): void
    {
        [, , , $pack] = $this->packWaitingOnTheLeader();
        $carla = $this->person('Maam Carla', 'leader', signsOff: true);

        $this->actingAs($carla)->post(route('tasks.approve', $pack))
            ->assertRedirect();

        $this->assertSame('complete', $pack->fresh()->status);
    }

    public function test_rommel_can_sign_it_off_though_he_is_the_artist_leader(): void
    {
        // Rommel is "artist leader" by job role, which does not read as a
        // leader at all - the grant is what says he may.
        [, , , $pack] = $this->packWaitingOnTheLeader();
        $rommel = $this->person('Rommel', 'artist leader', signsOff: true);

        $this->assertFalse($rommel->isLeader());

        $this->actingAs($rommel)->post(route('tasks.approve', $pack))
            ->assertRedirect();

        $this->assertSame('complete', $pack->fresh()->status);
    }

    public function test_a_supervisor_cannot_sign_the_tech_pack_off(): void
    {
        // The four supervisors read as leaders. This is the case the whole
        // change exists for.
        [, , , $pack] = $this->packWaitingOnTheLeader();
        $boying = $this->person('Sir Boying', 'supervisor');

        $this->assertTrue($boying->isLeader(), 'a supervisor still reads as a leader');

        $this->actingAs($boying)->post(route('tasks.approve', $pack))
            ->assertForbidden();

        $this->assertSame('for_checking', $pack->fresh()->status);
    }

    public function test_a_leader_without_the_grant_cannot_either(): void
    {
        [, , , $pack] = $this->packWaitingOnTheLeader();
        $otherLeader = $this->person('Some Leader', 'leader');

        $this->actingAs($otherLeader)->post(route('tasks.approve', $pack))
            ->assertForbidden();

        $this->assertSame('for_checking', $pack->fresh()->status);
    }

    public function test_nobody_signs_off_their_own_drawing(): void
    {
        [, $artist, , $pack] = $this->packWaitingOnTheLeader();

        // Even holding the grant: the pack in front of them is their own.
        $artist->update(['can_approve_tech_packs' => true]);

        $this->actingAs($artist->fresh())->post(route('tasks.approve', $pack))
            ->assertForbidden();
    }

    public function test_other_work_is_not_touched_by_this(): void
    {
        // A leader without the grant still approves everything else they
        // always did - this change is about the tech pack alone.
        [$officer, $artist, $order] = $this->packWaitingOnTheLeader();
        $leader = $this->person('Some Leader', 'leader');

        $other = Task::create([
            'production_order_id' => $order->id, 'department' => 'Quality control',
            'sequence' => 9, 'stage' => 8, 'status' => 'for_checking',
            'team' => User::JOB_PRODUCTION, 'approver_role' => 'leader',
            'submitted_at' => now(),
        ]);

        $this->actingAs($leader)->post(route('tasks.approve', $other))
            ->assertRedirect();

        $this->assertSame('complete', $other->fresh()->status);
    }
}
