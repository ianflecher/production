<?php

namespace Tests\Feature;

use App\Models\ProductionOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A due date inside the shop's lead time has to be a decision, not a slip.
 *
 * The calendar shows a tight deadline afterwards, in red — which is the wrong
 * moment, because by then the date has been promised to the client. The
 * question belongs on the form, while the date is still being typed.
 */
class RushDueDateWarningTest extends TestCase
{
    use RefreshDatabase;

    private function sales(): User
    {
        return User::factory()->create(['job_role' => User::ROLE_SALES, 'is_active' => true]);
    }

    /** The order form is step two, so it is opened through an inquiry. */
    private function formUrl(User $sales): string
    {
        $client = \App\Models\Client::create(['name' => 'Rush', 'last_name' => 'Client']);

        $inquiry = \App\Models\Inquiry::create([
            'client_id' => $client->id,
            'created_by' => $sales->id,
            'status' => \App\Models\Inquiry::STATUS_OPEN,
        ]);

        return '/orders/create?inquiry='.$inquiry->id;
    }

    public function test_the_order_form_asks_before_accepting_a_rush_date(): void
    {
        $sales = $this->sales();
        $this->actingAs($sales)->get($this->formUrl($sales))
            ->assertOk()
            ->assertSee('onsubmit="return confirmRush(this);"', false)
            // Asked through the app's own dialog, not the browser's grey box.
            ->assertSee('window.icConfirm(rushQuestion())', false)
            ->assertSee('Yes, keep this date', false);
    }

    public function test_the_edit_form_asks_too(): void
    {
        $sales = $this->sales();
        $order = ProductionOrder::create([
            'order_number' => 'IC2026-09100', 'customer_name' => 'Hurry Co',
            'product_type' => 'round_neck', 'quantity' => 10,
            'due_date' => now()->addDays(3), 'created_by' => $sales->id, 'status' => 'active',
        ]);

        // Changing a date is the same promise as making one.
        $this->actingAs($sales)->get("/orders/{$order->id}/edit")
            ->assertOk()
            ->assertSee('onsubmit="return confirmRush(this);"', false);
    }

    public function test_the_threshold_comes_from_the_model_not_a_number_in_a_script(): void
    {
        $sales = $this->sales();
        $this->actingAs($sales)->get($this->formUrl($sales))
            ->assertOk()
            ->assertSee('const RUSH_DAYS = '.ProductionOrder::RUSH_NOTICE_DAYS.';', false);
    }

    public function test_ten_days_is_the_line(): void
    {
        // Named so the rule can be found and changed in one place, rather than
        // living as a bare 10 in two Blade files.
        $this->assertSame(10, ProductionOrder::RUSH_NOTICE_DAYS);
    }

    public function test_a_comfortable_date_is_still_accepted_without_a_fuss(): void
    {
        // The confirm is client-side by design: the server must not start
        // refusing rush jobs, because sometimes the shop takes them.
        $sales = $this->sales();

        $this->actingAs($sales)->post('/orders', [
            'order_number' => 'IC2026-09101',
            'client_name' => 'Rush', 'client_last_name' => 'Job',
            'client_contact' => '0917-000-1111',
            'client_address' => 'Angeles City',
            'due_date' => now()->addDays(2)->toDateString(),
            'product_type' => 'round_neck',
            'sizes' => ['M' => 10],
        ])->assertRedirect();

        $this->assertDatabaseHas('production_orders', ['order_number' => 'IC2026-09101']);
    }

    public function test_the_dialog_is_the_apps_own_not_the_browsers(): void
    {
        // window.confirm() prints "127.0.0.1:8000 says" above the question,
        // cannot mark which button is the dangerous one, and on a shop screen
        // reads as something having gone wrong rather than a question.
        $sales = $this->sales();
        $html = $this->actingAs($sales)->get($this->formUrl($sales))->assertOk()->getContent();

        $this->assertStringContainsString('id="icConfirm"', $html, 'the dialog markup should be on the page');
        $this->assertStringNotContainsString('window.confirm(', $html,
            'the rush question must not fall back to the browser box');
    }

    public function test_the_dialog_is_available_on_every_page(): void
    {
        // It lives in the layout so anything can ask, rather than each page
        // rolling its own.
        $leader = User::factory()->create(['job_role' => User::ROLE_LEADER, 'is_active' => true]);

        foreach (['/dashboard', '/orders', '/calendar'] as $url) {
            $this->actingAs($leader)->get($url)->assertOk()->assertSee('id="icConfirm"', false);
        }
    }
}
