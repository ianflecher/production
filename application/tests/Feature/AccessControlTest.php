<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Locks in the role-based access model defined by the `role:` middleware in
 * routes/web.php. If a future change accidentally widens or removes a gate,
 * these tests fail. Permission role is DERIVED from `job_role` (User::getRoleAttribute).
 */
class AccessControlTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $jobRole): User
    {
        return User::factory()->create([
            'job_role' => $jobRole,
            'is_active' => true,
        ]);
    }

    // ---- Wrong role is forbidden (403) --------------------------------------

    public function test_agent_cannot_view_orders(): void
    {
        $this->actingAs($this->user(User::JOB_PRODUCTION))
            ->get('/orders')->assertForbidden();
    }

    public function test_agent_cannot_open_order_intake(): void
    {
        $this->actingAs($this->user(User::JOB_PRODUCTION))
            ->get('/orders/create')->assertForbidden();
    }

    public function test_sales_cannot_view_finance(): void
    {
        $this->actingAs($this->user(User::ROLE_SALES))
            ->get('/finance')->assertForbidden();
    }

    public function test_sales_cannot_manage_users(): void
    {
        $this->actingAs($this->user(User::ROLE_SALES))
            ->get('/users')->assertForbidden();
    }

    public function test_leader_cannot_open_order_intake(): void
    {
        // Order intake is sales/super_admin only.
        $this->actingAs($this->user(User::ROLE_LEADER))
            ->get('/orders/create')->assertForbidden();
    }

    public function test_finance_cannot_manage_users(): void
    {
        $this->actingAs($this->user(User::ROLE_FINANCE))
            ->get('/users')->assertForbidden();
    }

    // ---- Right role is allowed (page renders, not 403/500) ------------------

    public function test_super_admin_can_view_orders(): void
    {
        $this->actingAs($this->user(User::ROLE_SUPER_ADMIN))
            ->get('/orders')->assertOk();
    }

    public function test_sales_can_view_orders(): void
    {
        $this->actingAs($this->user(User::ROLE_SALES))
            ->get('/orders')->assertOk();
    }

    public function test_sales_can_open_order_intake(): void
    {
        // Intake starts with the client, so that is the page they open. The
        // order form is step two and is reached from an inquiry.
        $this->actingAs($this->user(User::ROLE_SALES))
            ->get('/inquiries/create')->assertOk();
    }

    public function test_the_order_form_sends_you_to_step_one_when_there_is_no_inquiry(): void
    {
        $this->actingAs($this->user(User::ROLE_SALES))
            ->get('/orders/create')
            ->assertRedirect(route('inquiries.create'));
    }

    public function test_finance_can_view_finance(): void
    {
        $this->actingAs($this->user(User::ROLE_FINANCE))
            ->get('/finance')->assertOk();
    }

    public function test_leader_can_manage_users(): void
    {
        $this->actingAs($this->user(User::ROLE_LEADER))
            ->get('/users')->assertOk();
    }

    public function test_any_active_user_can_reach_dashboard(): void
    {
        $this->actingAs($this->user(User::JOB_PRODUCTION))
            ->get('/dashboard')->assertOk();
    }
}
