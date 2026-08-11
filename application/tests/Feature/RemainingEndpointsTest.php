<?php

namespace Tests\Feature;

use App\Models\Payment;
use App\Models\ProductionOrder;
use App\Models\PushSubscription;
use App\Models\StationSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** The last few endpoints that had no coverage. */
class RemainingEndpointsTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $jobRole = 'sewing'): User
    {
        return User::factory()->create(['job_role' => $jobRole, 'is_active' => true]);
    }

    // ---- Profile photo -----------------------------------------------------

    public function test_a_user_can_set_a_profile_photo(): void
    {
        Storage::fake('public');
        $me = $this->user();

        $this->actingAs($me)->post('/account/profile-photo', [
            'profile_photo' => UploadedFile::fake()->image('me.jpg'),
        ])->assertRedirect();

        $path = $me->fresh()->profile_photo_path;
        $this->assertNotNull($path);
        Storage::disk('public')->assertExists($path);
    }

    public function test_replacing_the_photo_removes_the_old_file(): void
    {
        Storage::fake('public');
        $me = $this->user();

        $this->actingAs($me)->post('/account/profile-photo', ['profile_photo' => UploadedFile::fake()->image('one.jpg')]);
        $first = $me->fresh()->profile_photo_path;

        $this->actingAs($me)->post('/account/profile-photo', ['profile_photo' => UploadedFile::fake()->image('two.jpg')]);
        $second = $me->fresh()->profile_photo_path;

        $this->assertNotSame($first, $second);
        Storage::disk('public')->assertMissing($first);
        Storage::disk('public')->assertExists($second);
    }

    public function test_the_photo_must_be_an_image(): void
    {
        Storage::fake('public');

        $this->actingAs($this->user())->post('/account/profile-photo', [
            'profile_photo' => UploadedFile::fake()->create('resume.pdf', 40),
        ])->assertInvalid(['profile_photo']);
    }

    public function test_a_user_can_remove_their_photo(): void
    {
        Storage::fake('public');
        $me = $this->user();

        $this->actingAs($me)->post('/account/profile-photo', ['profile_photo' => UploadedFile::fake()->image('me.jpg')]);
        $path = $me->fresh()->profile_photo_path;

        $this->actingAs($me)->delete('/account/profile-photo')->assertRedirect();

        $this->assertNull($me->fresh()->profile_photo_path);
        Storage::disk('public')->assertMissing($path);
    }

    public function test_a_guest_cannot_set_a_photo(): void
    {
        Storage::fake('public');

        $this->post('/account/profile-photo', [
            'profile_photo' => UploadedFile::fake()->image('me.jpg'),
        ])->assertRedirect('/login');
    }

    // ---- Finance's copy of a payment proof ---------------------------------

    public function test_finance_can_open_any_payment_proof(): void
    {
        Storage::fake('local');
        $sales = $this->user(User::ROLE_SALES);

        $this->actingAs($sales)->post('/orders', [
            'order_number' => 'IC2026-09900',
            'client_name' => 'Proof Co',
            'client_last_name' => 'Cruz',
            'client_contact' => '0917-000-0000',
            'client_office_address' => 'Angeles City',
            'client_delivery_address' => 'Angeles City',
            'due_date' => now()->addWeeks(2)->toDateString(),
            'product_type' => 'round_neck',
            'sizes' => ['M' => 10],
        ]);
        $order = ProductionOrder::where('order_number', 'IC2026-09900')->firstOrFail();

        $payment = Payment::create([
            'production_order_id' => $order->id,
            'amount' => 500,
            'method' => 'GCash',
            'kind' => 'downpayment',
            'proof_path' => UploadedFile::fake()->image('proof.jpg')->store('payment-proofs', 'local'),
            'proof_name' => 'proof.jpg',
            'recorded_by' => $sales->id,
        ]);

        // Finance sees every order's proof, not just their own.
        $this->actingAs($this->user(User::ROLE_FINANCE))
            ->get("/finance/payments/{$payment->id}/proof")
            ->assertOk();
    }

    public function test_an_agent_cannot_open_a_proof_through_the_finance_route(): void
    {
        Storage::fake('local');
        $sales = $this->user(User::ROLE_SALES);

        $this->actingAs($sales)->post('/orders', [
            'order_number' => 'IC2026-09902',
            'client_name' => 'Proof Co',
            'client_last_name' => 'Cruz',
            'client_contact' => '0917-000-0000',
            'client_office_address' => 'Angeles City',
            'client_delivery_address' => 'Angeles City',
            'due_date' => now()->addWeeks(2)->toDateString(),
            'product_type' => 'round_neck',
            'sizes' => ['M' => 10],
        ]);
        $order = ProductionOrder::where('order_number', 'IC2026-09902')->firstOrFail();

        $payment = Payment::create([
            'production_order_id' => $order->id,
            'amount' => 500,
            'method' => 'GCash',
            'kind' => 'downpayment',
            'proof_path' => UploadedFile::fake()->image('proof.jpg')->store('payment-proofs', 'local'),
            'proof_name' => 'proof.jpg',
            'recorded_by' => $sales->id,
        ]);

        // A real payment, so it's the role gate being tested and not a 404.
        $this->actingAs($this->user())
            ->get("/finance/payments/{$payment->id}/proof")
            ->assertForbidden();
    }

    // ---- Push notifications ------------------------------------------------

    public function test_a_user_can_unsubscribe_from_push(): void
    {
        $me = $this->user();
        $endpoint = 'https://push.example.com/abc123';

        $this->actingAs($me)->postJson('/push/subscribe', [
            'endpoint' => $endpoint,
            'keys' => ['p256dh' => 'key', 'auth' => 'token'],
        ])->assertOk();
        $this->assertSame(1, PushSubscription::count());

        $this->actingAs($me)->postJson('/push/unsubscribe', ['endpoint' => $endpoint])->assertOk();

        $this->assertSame(0, PushSubscription::count(), 'the subscription should be gone');
    }

    public function test_a_guest_cannot_unsubscribe(): void
    {
        $this->postJson('/push/unsubscribe', ['endpoint' => 'https://push.example.com/x'])
            ->assertUnauthorized();
    }

    // ---- Ending a station run ----------------------------------------------

    public function test_a_running_station_can_be_ended(): void
    {
        $operator = $this->user('sewing');

        $sales = $this->user(User::ROLE_SALES);
        $this->actingAs($sales)->post('/orders', [
            'order_number' => 'IC2026-09901',
            'client_name' => 'Station Co',
            'client_last_name' => 'Cruz',
            'client_contact' => '0917-000-0000',
            'client_office_address' => 'Angeles City',
            'client_delivery_address' => 'Angeles City',
            'due_date' => now()->addWeeks(2)->toDateString(),
            'product_type' => 'round_neck',
            'sizes' => ['M' => 10],
        ]);
        $order = ProductionOrder::where('order_number', 'IC2026-09901')->firstOrFail();

        $session = StationSession::create([
            'station' => 'sewing_1',
            'user_id' => $operator->id,
            'production_order_id' => $order->id,
            'operator_name' => 'Geneline',
            'started_at' => now()->subHour(),
        ]);

        $this->actingAs($operator)
            ->post("/station-sessions/{$session->id}/end", ['end_reason' => 'break'])
            ->assertRedirect();

        $this->assertNotNull($session->fresh()->ended_at);
        $this->assertSame('break', $session->fresh()->end_reason);
    }

    public function test_ending_a_station_needs_a_reason(): void
    {
        $operator = $this->user('sewing');

        $session = StationSession::create([
            'station' => 'sewing_1',
            'user_id' => $operator->id,
            'operator_name' => 'Geneline',
            'started_at' => now()->subHour(),
        ]);

        $this->actingAs($operator)
            ->post("/station-sessions/{$session->id}/end", [])
            ->assertInvalid(['end_reason']);

        $this->assertNull($session->fresh()->ended_at);
    }

    public function test_a_station_run_cannot_be_ended_twice(): void
    {
        $operator = $this->user('sewing');

        $session = StationSession::create([
            'station' => 'sewing_1',
            'user_id' => $operator->id,
            'operator_name' => 'Geneline',
            'started_at' => now()->subHours(2),
            'ended_at' => now()->subHour(),
            'end_reason' => 'finished',
        ]);

        // A second submit is a double-click or a stale form, not an offence:
        // it says the run is already finished and goes back to the board.
        $this->actingAs($operator)
            ->post("/station-sessions/{$session->id}/end", ['end_reason' => 'break'])
            ->assertRedirect(route('stations.index'));
    }
}
