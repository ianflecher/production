<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Staff accounts: creation, deactivation, and who may manage them. */
class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    private function superAdmin(): User
    {
        return User::factory()->create(['job_role' => User::ROLE_SUPER_ADMIN, 'is_active' => true]);
    }

    public function test_super_admin_can_create_a_staff_account(): void
    {
        $this->actingAs($this->superAdmin())->post('/users', [
            'name' => 'New Sewer',
            'email' => 'sewer@example.com',
            'password' => 'secret-password',
            'position' => User::JOB_PRODUCTION,
        ])->assertRedirect();

        $this->assertDatabaseHas('users', ['email' => 'sewer@example.com', 'job_role' => User::JOB_PRODUCTION]);
    }

    public function test_new_account_password_is_hashed_not_plain_text(): void
    {
        $this->actingAs($this->superAdmin())->post('/users', [
            'name' => 'Hash Check',
            'email' => 'hash@example.com',
            'password' => 'secret-password',
            'position' => User::JOB_PRODUCTION,
        ]);

        $stored = User::where('email', 'hash@example.com')->value('password');
        $this->assertNotSame('secret-password', $stored, 'password must never be stored in plain text');
        $this->assertTrue(password_verify('secret-password', $stored));
    }

    public function test_email_must_be_unique(): void
    {
        $admin = $this->superAdmin();
        $payload = [
            'name' => 'Dup',
            'email' => 'dup@example.com',
            'password' => 'secret-password',
            'position' => User::JOB_PRODUCTION,
        ];

        $this->actingAs($admin)->post('/users', $payload);
        $this->actingAs($admin)->post('/users', $payload)->assertInvalid(['email']);
    }

    public function test_account_creation_requires_core_fields(): void
    {
        $this->actingAs($this->superAdmin())->post('/users', [])
            ->assertInvalid(['name', 'email', 'password', 'position']);
    }

    public function test_toggling_a_user_deactivates_then_reactivates_them(): void
    {
        $admin = $this->superAdmin();
        $staff = User::factory()->create(['job_role' => User::JOB_PRODUCTION, 'is_active' => true]);

        $this->actingAs($admin)->post("/users/{$staff->id}/toggle")->assertRedirect();
        $this->assertFalse((bool) $staff->fresh()->is_active, 'should be deactivated');

        $this->actingAs($admin)->post("/users/{$staff->id}/toggle");
        $this->assertTrue((bool) $staff->fresh()->is_active, 'should be reactivated');
    }

    public function test_deactivated_user_is_signed_out_of_the_app(): void
    {
        $admin = $this->superAdmin();
        $staff = User::factory()->create(['job_role' => User::JOB_PRODUCTION, 'is_active' => true]);

        $this->actingAs($admin)->post("/users/{$staff->id}/toggle");

        // The 'active' middleware must now bounce them to login.
        $this->actingAs($staff->fresh())->get('/dashboard')->assertRedirect('/login');
    }

    public function test_you_cannot_deactivate_your_own_account(): void
    {
        $admin = $this->superAdmin();

        $this->actingAs($admin)->post("/users/{$admin->id}/toggle")->assertInvalid(['user']);
        $this->assertTrue((bool) $admin->fresh()->is_active, 'admin must not lock themselves out');
    }

    public function test_a_leader_cannot_deactivate_a_super_admin(): void
    {
        $leader = User::factory()->create(['job_role' => User::ROLE_LEADER, 'is_active' => true]);
        $admin = $this->superAdmin();

        $this->actingAs($leader)->post("/users/{$admin->id}/toggle")->assertForbidden();
        $this->assertTrue((bool) $admin->fresh()->is_active);
    }

    public function test_agent_cannot_toggle_accounts(): void
    {
        $agent = User::factory()->create(['job_role' => User::JOB_PRODUCTION, 'is_active' => true]);
        $victim = User::factory()->create(['job_role' => 'sewing', 'is_active' => true]);

        $this->actingAs($agent)->post("/users/{$victim->id}/toggle")->assertForbidden();
        $this->assertTrue((bool) $victim->fresh()->is_active);
    }

    public function test_agent_cannot_manage_users(): void
    {
        $agent = User::factory()->create(['job_role' => User::JOB_PRODUCTION, 'is_active' => true]);

        $this->actingAs($agent)->get('/users')->assertForbidden();
        $this->actingAs($agent)->post('/users', [
            'name' => 'Sneaky',
            'email' => 'sneaky@example.com',
            'password' => 'secret-password',
            'position' => User::ROLE_SUPER_ADMIN,
        ])->assertForbidden();

        $this->assertDatabaseMissing('users', ['email' => 'sneaky@example.com']);
    }
}
