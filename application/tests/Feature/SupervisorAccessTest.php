<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Regression: a supervisor came out of getRoleAttribute() as ROLE_AGENT while
 * isLeader() reported true. The `role:` middleware trusts the derived role, so
 * every leader page returned 403 — including /users, which already contains
 * logic to scope the list for supervisors.
 */
class SupervisorAccessTest extends TestCase
{
    use RefreshDatabase;

    private function supervisor(string $spelling = 'Supervisor'): User
    {
        return User::factory()->create(['job_role' => $spelling, 'is_active' => true]);
    }

    public function test_a_supervisor_is_treated_as_a_leader(): void
    {
        $s = $this->supervisor();

        $this->assertSame(User::ROLE_LEADER, $s->role);
        $this->assertTrue($s->isLeader());
        $this->assertTrue($s->isSupervisor());
        $this->assertFalse($s->isAgent(), 'a supervisor is not floor labour');
    }

    #[DataProvider('leaderPages')]
    public function test_a_supervisor_can_open_the_leader_pages(string $path): void
    {
        $this->actingAs($this->supervisor())->get($path)->assertOk();
    }

    public static function leaderPages(): array
    {
        return [
            'approvals' => ['/approvals'],
            'users' => ['/users'],
            'orders' => ['/orders'],
            'calendar' => ['/calendar'],
            'dashboard' => ['/dashboard'],
            'stations' => ['/stations'],
        ];
    }

    public function test_the_spelling_of_the_job_role_does_not_matter(): void
    {
        foreach (['supervisor', 'SUPERVISOR', '  Supervisor  '] as $spelling) {
            $this->assertSame(
                User::ROLE_LEADER,
                $this->supervisor($spelling)->role,
                "'$spelling' should still be a supervisor"
            );
        }
    }

    public function test_a_supervisor_can_act_on_the_pipeline(): void
    {
        $sales = User::factory()->create(['job_role' => User::ROLE_SALES, 'is_active' => true]);
        $this->actingAs($sales)->post('/orders', [
            'order_number' => 'IC2026-06666',
            'client_name' => 'Supervisor Co',
            'client_last_name' => 'Cruz',
            'client_contact' => '0917-000-0000',
            'client_address' => 'Angeles City',
            'due_date' => now()->addWeeks(3)->toDateString(),
            'product_type' => 'round_neck',
            'sizes' => ['M' => 10],
        ]);
        $order = \App\Models\ProductionOrder::where('order_number', 'IC2026-06666')->firstOrFail();

        // Putting an order on hold is a leader-only action.
        $this->actingAs($this->supervisor())
            ->post("/orders/{$order->id}/status", ['action' => 'hold'])
            ->assertRedirect();

        $this->assertSame('on_hold', $order->fresh()->status);
    }

    public function test_an_ordinary_agent_is_still_shut_out(): void
    {
        $agent = User::factory()->create(['job_role' => 'sewing', 'is_active' => true]);

        $this->actingAs($agent)->get('/approvals')->assertForbidden();
        $this->actingAs($agent)->get('/users')->assertForbidden();
    }
}
