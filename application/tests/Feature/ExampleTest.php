<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The root path used to redirect everybody to the dashboard, which sent a
     * guest on to the login form and left somebody wanting to apply for a job
     * with nowhere to go. It now asks which they are - see
     * TheFrontDoorAsksWhichYouAreTest for the whole of that behaviour.
     */
    public function test_the_root_path_sends_a_signed_in_person_to_the_dashboard(): void
    {
        $user = User::factory()->create(['job_role' => 'printer', 'is_active' => true]);

        $this->actingAs($user)->get('/')->assertRedirect('/dashboard');
    }

    public function test_the_root_path_asks_a_guest_which_they_are(): void
    {
        $this->get('/')->assertOk()->assertSee('Which are you?');
    }
}
