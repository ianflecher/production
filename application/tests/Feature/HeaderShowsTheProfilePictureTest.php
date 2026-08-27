<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The picture somebody chose is the one beside their name.
 *
 * My Account says the picture "can be displayed beside your name throughout the
 * production system", and the header is the one place a person's name is always
 * beside them. It drew their initials instead, so somebody who had just
 * uploaded a photograph saw a letter in a circle everywhere they looked.
 *
 * Initials are still the answer for anyone who has not chosen one.
 */
class HeaderShowsTheProfilePictureTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_chosen_picture_is_the_one_shown(): void
    {
        $user = User::factory()->create([
            'job_role' => User::JOB_ARTIST,
            'is_active' => true,
            'name' => 'Maru Delos Reyes',
            'profile_photo_path' => 'avatars/maru.jpg',
        ]);

        $this->actingAs($user)->get('/dashboard')
            ->assertOk()
            ->assertSee(asset('storage/avatars/maru.jpg'), false);
    }

    public function test_initials_are_kept_for_anyone_without_one(): void
    {
        $user = User::factory()->create([
            'job_role' => User::JOB_ARTIST,
            'is_active' => true,
            'name' => 'Maru Delos Reyes',
            'profile_photo_path' => null,
        ]);

        $html = $this->actingAs($user)->get('/dashboard')->assertOk()->getContent();

        // First name, then surname — MD, not just M.
        $this->assertStringContainsString('MD', $html);
        $this->assertStringNotContainsString('storage/avatars', $html);
    }

    public function test_the_circle_holds_the_picture_rather_than_overflowing_it(): void
    {
        // The avatar is a 36px circle laid out with grid: without these two
        // rules a photograph spills out of it as a square.
        $css = file_get_contents(public_path('css/app.css'));

        $this->assertMatchesRegularExpression('/\.avatar\s*\{[^}]*overflow:\s*hidden/s', $css);
        $this->assertMatchesRegularExpression('/\.avatar img\s*\{[^}]*object-fit:\s*cover/s', $css);
    }
}
