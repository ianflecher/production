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

    public function test_reset_password_puts_the_account_back_to_the_default(): void
    {
        $admin = $this->superAdmin();
        $staff = User::factory()->create([
            'password' => 'something-they-chose',
            'job_role' => User::JOB_PRODUCTION,
            'is_active' => true,
        ]);

        // No password is typed — the button alone does it.
        $this->actingAs($admin)->post("/users/{$staff->id}/reset-password")->assertRedirect();

        $this->assertTrue(
            \Illuminate\Support\Facades\Hash::check(User::DEFAULT_PASSWORD, $staff->fresh()->password),
            'the account should be back to the default password'
        );
    }

    public function test_the_reset_password_is_still_stored_hashed(): void
    {
        $admin = $this->superAdmin();
        $staff = User::factory()->create(['job_role' => User::JOB_PRODUCTION, 'is_active' => true]);

        $this->actingAs($admin)->post("/users/{$staff->id}/reset-password");

        $this->assertNotSame(User::DEFAULT_PASSWORD, $staff->fresh()->password);
    }

    public function test_the_reset_account_can_actually_log_in_afterwards(): void
    {
        $admin = $this->superAdmin();
        $staff = User::factory()->create([
            'email' => 'resetme@example.com',
            'password' => 'forgotten',
            'job_role' => User::JOB_PRODUCTION,
            'is_active' => true,
        ]);

        $this->actingAs($admin)->post("/users/{$staff->id}/reset-password");

        auth()->logout();
        $this->flushSession();

        $this->post('/login', [
            'email' => 'resetme@example.com',
            'password' => User::DEFAULT_PASSWORD,
        ]);

        $this->assertAuthenticatedAs($staff->fresh());
    }

    public function test_a_leader_cannot_reset_a_super_admins_password(): void
    {
        $leader = User::factory()->create(['job_role' => User::ROLE_LEADER, 'is_active' => true]);
        $admin = $this->superAdmin();
        $before = $admin->password;

        $this->actingAs($leader)->post("/users/{$admin->id}/reset-password")->assertForbidden();

        $this->assertSame($before, $admin->fresh()->password);
    }

    public function test_an_agent_cannot_reset_anyones_password(): void
    {
        $agent = User::factory()->create(['job_role' => User::JOB_PRODUCTION, 'is_active' => true]);
        $victim = User::factory()->create([
            'password' => 'theirs',
            'job_role' => 'sewing',
            'is_active' => true,
        ]);
        $before = $victim->password;

        $this->actingAs($agent)->post("/users/{$victim->id}/reset-password")->assertForbidden();

        $this->assertSame($before, $victim->fresh()->password);
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

    /**
     * On a phone the list stops being a table, so its cells name themselves.
     *
     * Five columns need 622 pixels of table and a phone has about 330, so
     * below 700px each account becomes a card. The headings go with the
     * table, and the only thing left telling a reader that
     * sales6@imprintcustoms.ph is the email is the label on the cell. A
     * column added later without one would show on a phone as a value under
     * nothing at all - which is invisible on the desktop this is written on.
     */
    public function test_every_cell_on_the_list_says_what_it_is(): void
    {
        User::factory()->create(['job_role' => User::JOB_ARTIST, 'is_active' => true]);

        $page = $this->actingAs($this->superAdmin())
            ->get(route('users.index'))->assertOk()->getContent();

        preg_match('#<table class="tbl tbl-stack">.*?</table>#s', $page, $table);
        $this->assertNotEmpty($table, 'the list is no longer the table that stacks');

        preg_match('#<thead>.*?</thead>#s', $table[0], $head);
        // <th[ >] rather than <th, or the opening <thead> counts as a column.
        $columns = preg_match_all('#<th[ >]#', $head[0] ?? '');

        $this->assertSame(5, $columns);

        foreach (['Name', 'Email', 'Position', 'Today', 'Actions'] as $label) {
            $this->assertStringContainsString('data-label="'.$label.'"', $table[0],
                "the {$label} column has no label, so on a phone it is a value under nothing");
        }
    }
}
