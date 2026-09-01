<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Inquiry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A leader can hand a layout to a different artist before there is a job order.
 *
 * The artist is picked automatically when the brief is sent, and nothing could
 * change it until the order existed — so an artist who went home sick took the
 * layout with them and the officer could only wait.
 */
class LeaderMovesTheLayoutArtistTest extends TestCase
{
    use RefreshDatabase;

    private function layout(): array
    {
        $officer = User::factory()->create(['job_role' => User::ROLE_SALES, 'is_active' => true]);
        $mick = User::factory()->create(['job_role' => 'artist', 'is_active' => true, 'name' => 'Mick']);
        $rommel = User::factory()->create(['job_role' => 'artist', 'is_active' => true, 'name' => 'Rommel']);

        $inquiry = Inquiry::create([
            'client_id' => Client::create([
                'name' => 'Juan', 'last_name' => 'Dela Cruz', 'contact_number' => '0917',
                'office_address' => 'Angeles City', 'delivery_address' => 'Angeles City',
                'created_by' => $officer->id,
            ])->id,
            'created_by' => $officer->id,
            'status' => Inquiry::STATUS_OPEN,
            'layout_status' => Inquiry::LAYOUT_WITH_ARTIST,
            'layout_artist_id' => $mick->id,
            'layout_sent_at' => now()->subDay(),
        ]);

        return [$officer, $mick, $rommel, $inquiry];
    }

    private function leader(string $jobRole = 'leader'): User
    {
        return User::factory()->create(['job_role' => $jobRole, 'is_active' => true]);
    }

    public function test_a_leader_moves_the_layout_with_no_job_order_in_sight(): void
    {
        [, , $rommel, $inquiry] = $this->layout();

        $this->assertNull($inquiry->production_order_id, 'there is no job order yet');

        $this->actingAs($this->leader())
            ->post(route('inquiries.layout.artist', $inquiry), ['layout_artist_id' => $rommel->id])
            ->assertRedirect();

        $this->assertSame($rommel->id, $inquiry->refresh()->layout_artist_id);
    }

    public function test_it_lands_on_the_new_artists_queue_and_leaves_the_old_one(): void
    {
        [, $mick, $rommel, $inquiry] = $this->layout();

        $this->actingAs($this->leader())
            ->post(route('inquiries.layout.artist', $inquiry), ['layout_artist_id' => $rommel->id])
            ->assertRedirect();

        $this->actingAs($rommel)->get(route('inquiries.layouts'))
            ->assertOk()->assertSee('Dela Cruz');

        $this->actingAs($mick)->get(route('inquiries.layouts'))
            ->assertOk()->assertDontSee('Dela Cruz');
    }

    public function test_both_artists_are_told(): void
    {
        [, $mick, $rommel, $inquiry] = $this->layout();

        $this->actingAs($this->leader())
            ->post(route('inquiries.layout.artist', $inquiry), ['layout_artist_id' => $rommel->id])
            ->assertRedirect();

        $this->assertDatabaseHas('app_notifications', ['user_id' => $rommel->id]);
        $this->assertDatabaseHas('app_notifications', ['user_id' => $mick->id]);
    }

    public function test_a_supervisor_counts_as_a_leader_here(): void
    {
        [, , $rommel, $inquiry] = $this->layout();

        $this->actingAs($this->leader('Supervisor'))
            ->post(route('inquiries.layout.artist', $inquiry), ['layout_artist_id' => $rommel->id])
            ->assertRedirect();

        $this->assertSame($rommel->id, $inquiry->refresh()->layout_artist_id);
    }

    public function test_the_officer_cannot_move_it_themselves(): void
    {
        [$officer, $mick, $rommel, $inquiry] = $this->layout();

        $this->actingAs($officer)
            ->post(route('inquiries.layout.artist', $inquiry), ['layout_artist_id' => $rommel->id])
            ->assertForbidden();

        $this->assertSame($mick->id, $inquiry->refresh()->layout_artist_id);
    }

    public function test_an_artist_cannot_take_it_off_somebody_else(): void
    {
        [, $mick, $rommel, $inquiry] = $this->layout();

        $this->actingAs($rommel)
            ->post(route('inquiries.layout.artist', $inquiry), ['layout_artist_id' => $rommel->id])
            ->assertForbidden();

        $this->assertSame($mick->id, $inquiry->refresh()->layout_artist_id);
    }

    public function test_it_can_only_go_to_an_artist(): void
    {
        [, $mick, , $inquiry] = $this->layout();
        $printer = User::factory()->create(['job_role' => 'printer', 'is_active' => true]);

        $this->actingAs($this->leader())
            ->post(route('inquiries.layout.artist', $inquiry), ['layout_artist_id' => $printer->id])
            ->assertRedirect();

        $this->assertSame($mick->id, $inquiry->refresh()->layout_artist_id,
            'a printer does not draw layouts');
    }

    public function test_it_cannot_go_to_somebody_who_has_left(): void
    {
        [, $mick, , $inquiry] = $this->layout();
        $gone = User::factory()->create(['job_role' => 'artist', 'is_active' => false]);

        $this->actingAs($this->leader())
            ->post(route('inquiries.layout.artist', $inquiry), ['layout_artist_id' => $gone->id])
            ->assertRedirect();

        $this->assertSame($mick->id, $inquiry->refresh()->layout_artist_id);
    }

    public function test_the_leader_sees_the_control_and_the_officer_does_not(): void
    {
        [$officer, , , $inquiry] = $this->layout();

        $this->actingAs($this->leader())->get(route('inquiries.layout', $inquiry))
            ->assertOk()->assertSee('Hand it to someone else');

        $this->actingAs($officer)->get(route('inquiries.layout', $inquiry))
            ->assertOk()->assertDontSee('Hand it to someone else');
    }

    public function test_moving_it_to_whoever_already_has_it_changes_nothing(): void
    {
        [, $mick, , $inquiry] = $this->layout();

        $this->actingAs($this->leader())
            ->post(route('inquiries.layout.artist', $inquiry), ['layout_artist_id' => $mick->id])
            ->assertRedirect();

        $this->assertSame($mick->id, $inquiry->refresh()->layout_artist_id);
        $this->assertDatabaseMissing('app_notifications', ['user_id' => $mick->id]);
    }
}
