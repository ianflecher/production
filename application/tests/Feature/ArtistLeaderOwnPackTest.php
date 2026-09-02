<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ProductionOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The artist leader draws at the bench too, and nobody checks their own work.
 *
 * His own pack is already kept out of the checking queue. The badge counted it
 * anyway, so the nav said "1" and the page it opened said "nothing to check" —
 * and the tech pack he WAS asked to check answered 403, because the sheet lived
 * behind the account officer's routes and he is not one.
 */
class ArtistLeaderOwnPackTest extends TestCase
{
    use RefreshDatabase;

    private function lead(): User
    {
        return User::factory()->create(['job_role' => User::JOB_ARTIST_LEAD, 'is_active' => true]);
    }

    private function artist(): User
    {
        return User::factory()->create(['job_role' => User::JOB_ARTIST, 'is_active' => true]);
    }

    /** An order whose tech pack is submitted by $drawnBy. */
    private function packFrom(User $drawnBy, string $number): ProductionOrder
    {
        $order = ProductionOrder::create([
            'order_number' => $number,
            'client_id' => Client::create(['name' => 'A', 'last_name' => 'Client'])->id,
            'customer_name' => 'A Client',
            'product_type' => 'round_neck',
            'quantity' => 10,
            'due_date' => now()->addWeeks(2),
            'status' => 'active',
            'created_by' => User::factory()->create(['job_role' => User::ROLE_SALES])->id,
        ]);

        $order->items()->create(['size' => 'M', 'quantity' => 10]);
        $order->refresh()->buildPipeline([], 'manual');

        $order->tasks()
            ->where('stage', ProductionOrder::STAGE_MOCKUP)
            ->where('department', 'Tech pack')
            ->get()
            ->each(fn ($t) => $t->forceFill([
                'assigned_to' => $drawnBy->id,
                'status' => 'for_checking',
                'submitted_at' => now(),
                'approver_role' => 'leader',
                'officer_approved_by' => $order->created_by,
                'officer_approved_at' => now(),
            ])->save());

        return $order;
    }

    /** The number the nav shows him. */
    private function badge(User $user): ?int
    {
        $html = $this->actingAs($user)->get(route('approvals'))->assertOk()->getContent();

        return preg_match('#Tech packs to check\s*<span class="count-pill">(\d+)</span>#s', $html, $m)
            ? (int) $m[1]
            : null;
    }

    public function test_his_own_pack_is_not_counted_on_the_badge(): void
    {
        $lead = $this->lead();
        $this->packFrom($lead, 'IC2026-P001');

        $this->assertNull($this->badge($lead),
            'the queue rejects his own pack, so the badge must not count it');

        $this->actingAs($lead)->get(route('approvals'))
            ->assertOk()
            ->assertDontSee('IC2026-P001', false);
    }

    public function test_somebody_elses_pack_is_counted(): void
    {
        $lead = $this->lead();
        $this->packFrom($this->artist(), 'IC2026-P002');

        $this->assertSame(1, $this->badge($lead));
    }

    public function test_the_badge_and_the_queue_agree_when_both_exist(): void
    {
        $lead = $this->lead();

        $this->packFrom($lead, 'IC2026-P003');        // his own — neither
        $this->packFrom($this->artist(), 'IC2026-P004'); // somebody's — both

        $this->assertSame(1, $this->badge($lead));

        $this->actingAs($lead)->get(route('approvals'))
            ->assertOk()
            ->assertSee('IC2026-P004', false)
            ->assertDontSee('IC2026-P003', false);
    }

    public function test_he_can_open_the_tech_pack_he_is_asked_to_check(): void
    {
        $lead = $this->lead();
        $order = $this->packFrom($this->artist(), 'IC2026-P005');

        $this->actingAs($lead)->get(route('orders.job-order', $order))->assertOk();
    }

    public function test_the_sheet_is_still_shut_to_the_floor(): void
    {
        $order = $this->packFrom($this->artist(), 'IC2026-P006');
        $sewer = User::factory()->create(['job_role' => 'Sewing', 'is_active' => true]);

        $this->actingAs($sewer)->get(route('orders.job-order', $order))->assertForbidden();
    }
}
