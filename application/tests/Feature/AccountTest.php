<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** A user's own account: password, display name, push alerts, sign-out. */
class AccountTest extends TestCase
{
    use RefreshDatabase;

    private function staff(): User
    {
        return User::factory()->create([
            'password' => 'old-password',
            'job_role' => 'sewing',
            'is_active' => true,
        ]);
    }

    public function test_a_user_can_change_their_password(): void
    {
        $user = $this->staff();

        $this->actingAs($user)->post('/account/password', [
            'current_password' => 'old-password',
            'password' => 'brand-new-password',
            'password_confirmation' => 'brand-new-password',
        ])->assertRedirect();

        $this->assertTrue(Hash::check('brand-new-password', $user->fresh()->password));
    }

    public function test_changing_password_requires_the_current_one(): void
    {
        $user = $this->staff();

        $this->actingAs($user)->post('/account/password', [
            'current_password' => 'wrong-password',
            'password' => 'brand-new-password',
            'password_confirmation' => 'brand-new-password',
        ])->assertInvalid(['current_password']);

        $this->assertTrue(Hash::check('old-password', $user->fresh()->password), 'password must be unchanged');
    }

    public function test_new_password_must_be_confirmed_and_long_enough(): void
    {
        $user = $this->staff();

        $this->actingAs($user)->post('/account/password', [
            'current_password' => 'old-password',
            'password' => 'short',
            'password_confirmation' => 'mismatch',
        ])->assertInvalid(['password']);

        $this->assertTrue(Hash::check('old-password', $user->fresh()->password));
    }

    public function test_a_user_can_change_their_display_name(): void
    {
        $user = $this->staff();

        $this->actingAs($user)->post('/account/name', ['name' => 'New Display Name'])
            ->assertRedirect();

        $this->assertSame('New Display Name', $user->fresh()->name);
    }

    public function test_a_user_can_subscribe_to_push_alerts(): void
    {
        $user = $this->staff();

        $this->actingAs($user)->postJson('/push/subscribe', [
            'endpoint' => 'https://push.example.com/abc123',
            'keys' => ['p256dh' => 'test-public-key', 'auth' => 'test-auth-token'],
        ])->assertOk()->assertJson(['ok' => true]);

        $this->assertDatabaseHas('push_subscriptions', ['user_id' => $user->id]);
    }

    public function test_push_subscribe_validates_its_payload(): void
    {
        $this->actingAs($this->staff())->postJson('/push/subscribe', [])
            ->assertJsonValidationErrors(['endpoint', 'keys.p256dh', 'keys.auth']);
    }

    public function test_a_guest_cannot_subscribe_to_push(): void
    {
        $this->postJson('/push/subscribe', [
            'endpoint' => 'https://push.example.com/abc123',
            'keys' => ['p256dh' => 'k', 'auth' => 'a'],
        ])->assertUnauthorized();

        $this->assertSame(0, \App\Models\PushSubscription::count());
    }

    public function test_a_user_can_log_out(): void
    {
        $user = $this->staff();

        $this->actingAs($user)->post('/logout')->assertRedirect();

        $this->assertGuest();
        $this->get('/dashboard')->assertRedirect('/login');
    }
}
