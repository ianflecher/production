<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Broad sweep: every list/index page in the app, loaded as a super admin
 * (who can reach everything). Catches crashes anywhere in the app — this is
 * the check that found the mockup-page 500.
 */
class AllPagesSmokeTest extends TestCase
{
    use RefreshDatabase;

    private function superAdmin(): User
    {
        return User::factory()->create([
            'job_role' => User::ROLE_SUPER_ADMIN,
            'is_active' => true,
        ]);
    }

    #[DataProvider('pages')]
    public function test_page_loads_for_super_admin(string $path): void
    {
        $response = $this->actingAs($this->superAdmin())->get($path);

        $this->assertLessThan(
            500,
            $response->getStatusCode(),
            "GET $path crashed with {$response->getStatusCode()}"
        );
        $this->assertSame(200, $response->getStatusCode(), "GET $path did not return 200");
    }

    public static function pages(): array
    {
        return [
            'dashboard' => ['/dashboard'],
            'account' => ['/account'],
            'calendar' => ['/calendar'],
            'orders' => ['/orders'],
            'orders create' => ['/orders/create'],
            'users' => ['/users'],
            'approvals' => ['/approvals'],
            'sample review' => ['/sample-review'],
            'order capacity' => ['/order-capacity'],
            'inventory' => ['/inventory'],
            'inventory history' => ['/inventory-history'],
            'inventory export' => ['/inventory-export'],
            'material requests' => ['/material-requests'],
            'products' => ['/products'],
            'stations' => ['/stations'],
            'my tasks' => ['/my-tasks'],
            'finance' => ['/finance'],
            'finance export' => ['/finance/export'],
            'poll version' => ['/poll/version'],
        ];
    }

    public function test_health_endpoint_is_public(): void
    {
        $this->get('/up')->assertOk();
    }
}
