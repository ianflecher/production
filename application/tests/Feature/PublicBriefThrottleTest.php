<?php
namespace Tests\Feature;
use App\Models\ProductionOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The public questionnaire is rate-limited against token guessing / hammering. */
class PublicBriefThrottleTest extends TestCase
{
    use RefreshDatabase;

    public function test_repeated_token_guessing_is_throttled(): void
    {
        $last = 200;
        for ($i = 0; $i < 40; $i++) {
            $last = $this->get("/imprint-customs/design-questionnaire/guess-{$i}")->getStatusCode();
            if ($last === 429) break;
        }
        $this->assertSame(429, $last, 'token guessing should hit the rate limit');
    }

    public function test_a_normal_client_visit_is_not_throttled(): void
    {
        $sales = User::factory()->create(['job_role' => User::ROLE_SALES, 'is_active' => true]);
        $this->actingAs($sales)->post('/orders', [
            'order_number' => 'IC2026-02020', 'client_name' => 'Throttle Co',
            'client_last_name' => 'Cruz', 'client_contact' => '0917-000-0000',
            'client_office_address' => 'Angeles City', 'client_delivery_address' => 'Angeles City',
            'due_date' => now()->addWeeks(3)->toDateString(),
            'product_type' => 'round_neck', 'sizes' => ['M' => 10],
        ]);
        $order = ProductionOrder::where('order_number','IC2026-02020')->firstOrFail();
        $order->regenerateBriefLink();
        auth()->logout(); $this->flushSession();

        // A few normal loads (client opening/refreshing the form) must all work.
        for ($i = 0; $i < 5; $i++) {
            $this->get("/imprint-customs/design-questionnaire/{$order->brief_token}")->assertOk();
        }
    }
}
