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

    /*
     * NOTE: there is deliberately no test for POST /users/{user}/toggle.
     * That route points at UserController@toggle, which does not exist — the
     * endpoint 500s and no UI links to it. Deactivating a user is currently
     * not possible through the app (the is_active flag and its middleware work,
     * but nothing can set it). Reported 2026-08-02; awaiting a decision to
     * either implement the toggle or drop the dead route.
     */

    public function test_deactivated_user_cannot_use_the_app(): void
    {
        // Set the flag directly, since no endpoint can currently do it.
        $staff = User::factory()->create(['job_role' => User::JOB_PRODUCTION, 'is_active' => false]);

        // The 'active' middleware must bounce them to login.
        $this->actingAs($staff)->get('/dashboard')->assertRedirect('/login');
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
