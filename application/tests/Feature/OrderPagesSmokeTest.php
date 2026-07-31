<?php

namespace Tests\Feature;

use App\Models\ProductionOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Characterization smoke tests: create a real order, then hit every order-scoped
 * page and lock in its CURRENT response status. These guard a mechanical refactor
 * of ProductionOrderController — if a route is rewired or a view breaks, the
 * status changes and a test fails. Not asserting business logic, just "the page
 * still responds the way it does today".
 */
class OrderPagesSmokeTest extends TestCase
{
    use RefreshDatabase;

    private function salesUser(): User
    {
        return User::factory()->create(['job_role' => User::ROLE_SALES, 'is_active' => true]);
    }

    private function makeOrder(User $user): ProductionOrder
    {
        $this->actingAs($user)->post('/orders', [
            'order_number' => 'IC2026-09500',
            'client_name' => 'Smoke Test Co',
            'due_date' => now()->addWeeks(2)->toDateString(),
            'product_type' => 'round_neck',
            'sizes' => ['M' => 10, 'L' => 5],
        ]);

        return ProductionOrder::where('order_number', 'IC2026-09500')->firstOrFail();
    }

    #[DataProvider('orderPages')]
    public function test_order_page_responds_as_expected(string $path, int $expected): void
    {
        $user = $this->salesUser();
        $order = $this->makeOrder($user);

        $url = str_replace('{id}', (string) $order->id, $path);
        $status = $this->actingAs($user)->get($url)->getStatusCode();

        $this->assertSame($expected, $status, "GET $url returned $status, expected $expected");
    }

    public static function orderPages(): array
    {
        // Locked to the statuses observed on the current (pre-refactor) code.
        return [
            'show'            => ['/orders/{id}', 200],
            'edit'            => ['/orders/{id}/edit', 200],
            'job-order view'  => ['/orders/{id}/job-order', 200],
            'mockup'          => ['/orders/{id}/mockup', 200],
            'reference'       => ['/orders/{id}/reference', 200],
            'design-brief'    => ['/orders/{id}/design-brief', 200],
            'document dr'     => ['/orders/{id}/document/dr', 200],
            'document pq'     => ['/orders/{id}/document/pq', 200],
            'job-order edit'  => ['/job-orders/{id}/edit', 200],
            'job-order prod'  => ['/job-orders/{id}/production', 200],
        ];
    }
}
