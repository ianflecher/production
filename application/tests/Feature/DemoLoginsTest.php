<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\DemoLogins;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A demo deployment lists its accounts on the login page, so somebody being
 * shown the system can switch roles without being handed credentials one at a
 * time.
 *
 * The office runs this same code against the real shop, so the important test
 * here is the one that says it stays hidden.
 */
class DemoLoginsTest extends TestCase
{
    use RefreshDatabase;

    private function staff(): void
    {
        foreach ([
            ['super_admin', 'Admin', 'admin@imprintcustoms.ph'],
            ['sales', 'Rey', 'sales1@imprintcustoms.ph'],
            ['leader', 'Nasser', 'leader@imprintcustoms.ph'],
            ['artist', 'Maru', 'artist1@imprintcustoms.ph'],
            ['Mover', 'Louiza', 'mover@imprintcustoms.ph'],
        ] as [$role, $name, $email]) {
            User::factory()->create([
                'job_role' => $role, 'name' => $name, 'email' => $email, 'is_active' => true,
                // The demo accounts share the default password, which is what
                // the panel offers.
                'password' => \Illuminate\Support\Facades\Hash::make(User::DEFAULT_PASSWORD),
            ]);
        }
    }

    public function test_it_is_off_unless_a_deployment_turns_it_on(): void
    {
        $this->staff();
        config(['app.demo_logins' => false]);

        $this->assertFalse(DemoLogins::enabled());
        $this->assertSame([], DemoLogins::all());

        $this->get('/login')
            ->assertOk()
            ->assertDontSee('Demo &mdash; pick a role', false)
            ->assertDontSee('admin@imprintcustoms.ph', false);
    }

    public function test_the_password_does_not_leak_when_it_is_off(): void
    {
        $this->staff();
        config(['app.demo_logins' => false]);

        // The fill-the-form script embedded the password whether or not the
        // panel rendered, which put it on the office's own login page.
        $page = $this->get('/login')->assertOk();

        $page->assertDontSee(User::DEFAULT_PASSWORD, false);
        $page->assertDontSee('demo-account', false);
        $page->assertDontSee('demo-logins', false);
    }

    public function test_the_default_is_off(): void
    {
        // Nothing is set: the office must not print logins on its front door.
        $this->assertFalse((bool) config('app.demo_logins'));
    }

    public function test_a_demo_lists_one_account_per_role(): void
    {
        $this->staff();
        config(['app.demo_logins' => true]);

        $page = $this->get('/login')->assertOk();

        $page->assertSee('Demo', false);
        foreach (['Super admin', 'Account officer', 'Leader', 'Artist', 'Mover'] as $role) {
            $page->assertSee($role, false);
        }
        $page->assertSee('admin@imprintcustoms.ph', false);
        $page->assertSee('mover@imprintcustoms.ph', false);
    }

    public function test_it_offers_the_shared_password(): void
    {
        $this->staff();
        config(['app.demo_logins' => true]);

        $this->get('/login')->assertOk()->assertSee(User::DEFAULT_PASSWORD, false);
    }

    public function test_a_role_nobody_holds_is_not_offered(): void
    {
        // Only an officer exists here — the rest must not appear as dead buttons.
        User::factory()->create(['job_role' => 'sales', 'email' => 'only@imprintcustoms.ph', 'is_active' => true]);
        config(['app.demo_logins' => true]);

        $offered = DemoLogins::all();

        $this->assertCount(1, $offered);
        $this->assertSame('only@imprintcustoms.ph', $offered[0]['email']);
    }

    public function test_a_deactivated_account_is_not_offered(): void
    {
        User::factory()->create(['job_role' => 'sales', 'email' => 'gone@imprintcustoms.ph', 'is_active' => false]);
        config(['app.demo_logins' => true]);

        $this->assertSame([], DemoLogins::all());
    }

    public function test_the_accounts_it_offers_can_actually_sign_in(): void
    {
        $this->staff();
        config(['app.demo_logins' => true]);

        // A button that does not work is worse than no button.
        foreach (DemoLogins::all() as $account) {
            $this->post('/login', [
                'email' => $account['email'],
                'password' => DemoLogins::password(),
            ])->assertRedirect();

            $this->assertAuthenticated();
            $this->post('/logout');
        }
    }
}
