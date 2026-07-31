<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_screen_can_be_rendered(): void
    {
        $this->get('/login')->assertOk();
    }

    public function test_users_can_authenticate_with_correct_credentials(): void
    {
        $user = User::factory()->create([
            'password' => 'password', // hashed via the model cast
            'job_role' => User::ROLE_SALES,
            'is_active' => true,
        ]);

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $this->assertAuthenticatedAs($user);
        $response->assertRedirect();
    }

    public function test_users_cannot_authenticate_with_wrong_password(): void
    {
        $user = User::factory()->create([
            'password' => 'password',
            'job_role' => User::ROLE_SALES,
            'is_active' => true,
        ]);

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'not-the-password',
        ]);

        $this->assertGuest();
    }

    public function test_deactivated_user_is_logged_out_on_next_request(): void
    {
        $user = User::factory()->create([
            'job_role' => User::ROLE_SALES,
            'is_active' => false,
        ]);

        // Even acting as the user, the 'active' middleware must reject them.
        $this->actingAs($user)
            ->get('/dashboard')
            ->assertRedirect('/login');

        $this->assertGuest();
    }

    public function test_guest_is_redirected_from_a_protected_page(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
    }
}
