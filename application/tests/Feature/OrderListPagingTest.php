<?php

namespace Tests\Feature;

use App\Models\ProductionOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The order list pages, and searches the whole book of orders rather than the
 * rows that happen to be on screen. The list only grows, so the page must stay
 * the same size no matter how many years of work sit behind it.
 */
class OrderListPagingTest extends TestCase
{
    use RefreshDatabase;

    private ?User $creator = null;

    private function leader(): User
    {
        return User::factory()->create(['job_role' => User::ROLE_LEADER, 'is_active' => true]);
    }

    /** @param array<string, mixed> $attributes */
    private function makeOrder(int $n, array $attributes = []): ProductionOrder
    {
        $this->creator ??= User::factory()->create(['job_role' => User::ROLE_SALES, 'is_active' => true]);

        return ProductionOrder::create(array_merge([
            'order_number' => sprintf('IC2026-%05d', $n),
            'customer_name' => 'Customer '.$n,
            'product_type' => 'round_neck',
            'quantity' => 10,
            'due_date' => now()->addWeeks(2)->toDateString(),
            'status' => 'active',
            'created_by' => $this->creator->id,
        ], $attributes));
    }

    public function test_the_list_shows_one_page_not_every_order(): void
    {
        foreach (range(1, 60) as $n) {
            $this->makeOrder($n);
        }

        $response = $this->actingAs($this->leader())->get('/orders');

        $response->assertOk();
        $this->assertCount(10, $response->viewData('orders')->items());
        $this->assertSame(60, $response->viewData('orders')->total());
    }

    public function test_the_summary_cards_count_every_order_not_just_the_page(): void
    {
        foreach (range(1, 60) as $n) {
            $this->makeOrder($n);
        }
        $this->makeOrder(900, ['status' => 'on_hold']);
        $this->makeOrder(901, ['status' => 'complete']);

        $response = $this->actingAs($this->leader())->get('/orders');

        $this->assertSame(62, $response->viewData('totalOrders'));
        $counts = $response->viewData('counts');
        $this->assertSame(60, $counts['active']);
        $this->assertSame(1, $counts['on_hold']);
        $this->assertSame(1, $counts['complete']);
    }

    /** The whole point of moving search to the server. */
    public function test_search_finds_an_order_that_is_not_on_the_first_page(): void
    {
        foreach (range(1, 60) as $n) {
            $this->makeOrder($n);
        }
        // Oldest id, so it sorts to the very last page.
        $buried = ProductionOrder::orderBy('id')->first();
        $buried->update(['customer_name' => 'Buried Trading Co']);

        $response = $this->actingAs($this->leader())->get('/orders?q=Buried');

        $response->assertOk();
        $response->assertSee('Buried Trading Co');
        $this->assertSame(1, $response->viewData('orders')->total());
    }

    public function test_search_matches_the_order_number_too(): void
    {
        $this->makeOrder(1, ['customer_name' => 'Alpha']);
        $this->makeOrder(2, ['customer_name' => 'Beta']);

        $response = $this->actingAs($this->leader())->get('/orders?q=IC2026-00002');

        $this->assertSame(1, $response->viewData('orders')->total());
        $this->assertSame('Beta', $response->viewData('orders')->first()->customer_name);
    }

    public function test_status_filter_narrows_the_list(): void
    {
        $this->makeOrder(1);
        $this->makeOrder(2, ['status' => 'cancelled']);

        $response = $this->actingAs($this->leader())->get('/orders?status=cancelled');

        $this->assertSame(1, $response->viewData('orders')->total());
        $this->assertSame('cancelled', $response->viewData('orders')->first()->status);
    }

    public function test_a_junk_status_is_ignored_rather_than_emptying_the_list(): void
    {
        $this->makeOrder(1);
        $this->makeOrder(2);

        $response = $this->actingAs($this->leader())->get('/orders?status=nonsense');

        $response->assertOk();
        $this->assertSame(2, $response->viewData('orders')->total());
        $this->assertSame('', $response->viewData('status'));
    }

    public function test_search_and_status_apply_together(): void
    {
        $this->makeOrder(1, ['customer_name' => 'Shared Name']);
        $this->makeOrder(2, ['customer_name' => 'Shared Name', 'status' => 'cancelled']);

        $response = $this->actingAs($this->leader())->get('/orders?q=Shared&status=cancelled');

        $this->assertSame(1, $response->viewData('orders')->total());
        $this->assertSame('cancelled', $response->viewData('orders')->first()->status);
    }

    /** An account officer's own-orders-only rule must survive searching and paging. */
    public function test_an_account_officer_cannot_search_up_someone_elses_order(): void
    {
        $mine = User::factory()->create(['job_role' => User::ROLE_SALES, 'is_active' => true]);
        $theirs = User::factory()->create(['job_role' => User::ROLE_SALES, 'is_active' => true]);

        $this->makeOrder(1, ['customer_name' => 'Hidden Corp', 'created_by' => $theirs->id]);
        $this->makeOrder(2, ['customer_name' => 'My Client', 'created_by' => $mine->id]);

        $response = $this->actingAs($mine)->get('/orders?q=Hidden');

        $response->assertOk();
        $response->assertDontSee('Hidden Corp');
        $this->assertSame(0, $response->viewData('orders')->total());

        // ...and the cards must not leak the other officer's order either.
        $this->assertSame(1, $this->actingAs($mine)->get('/orders')->viewData('totalOrders'));
    }

    /**
     * A job due today reads "Due today", not "Due in 0 days" — diffInDays comes
     * back as a float, so the strict === 0 test used to fall straight through.
     */
    public function test_a_job_due_today_says_due_today(): void
    {
        $this->makeOrder(1, ['due_date' => now()->toDateString()]);

        $response = $this->actingAs($this->leader())->get('/orders');

        $response->assertOk();
        $response->assertSee('Due today');
        $response->assertDontSee('Due in 0');
    }

    public function test_a_job_due_in_two_days_still_counts_the_days(): void
    {
        $this->makeOrder(1, ['due_date' => now()->addDays(2)->toDateString()]);

        $response = $this->actingAs($this->leader())->get('/orders');

        $response->assertSee('Due in');
        $response->assertDontSee('Due today');
    }

    public function test_paging_keeps_the_search_and_status_in_the_links(): void
    {
        foreach (range(1, 60) as $n) {
            $this->makeOrder($n, ['customer_name' => 'Bulk Buyer']);
        }

        $response = $this->actingAs($this->leader())->get('/orders?q=Bulk&status=active');

        $response->assertOk();
        $this->assertStringContainsString('q=Bulk', $response->viewData('orders')->nextPageUrl());
        $this->assertStringContainsString('status=active', $response->viewData('orders')->nextPageUrl());
    }
}
