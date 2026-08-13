<?php

namespace Tests\Feature;

use App\Models\ProductionOrder;
use App\Models\Task;
use App\Models\TaskFile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Where the artist's export path box starts from.
 *
 * Reached over the tunnel the server sees 127.0.0.1, not the artist's machine,
 * so it cannot read their address off the connection. The two things it can do
 * are remember where they put the last one, and keep their office address up to
 * date whenever they are actually in the office.
 */
class ArtistExportPathTest extends TestCase
{
    use RefreshDatabase;

    private function exportTask(User $artist): Task
    {
        $sales = User::factory()->create(['job_role' => User::ROLE_SALES, 'is_active' => true]);

        $order = ProductionOrder::create([
            'order_number' => 'IC2026-0'.random_int(1000, 9999),
            'customer_name' => 'Export Co', 'product_type' => 'round_neck', 'quantity' => 10,
            'due_date' => now()->addWeek(), 'created_by' => $sales->id, 'status' => 'active',
        ]);

        return Task::create([
            'production_order_id' => $order->id,
            'department' => 'Export',
            'sequence' => 5, 'stage' => 3, 'status' => 'ready',
            'assigned_to' => $artist->id,
        ]);
    }

    /*
     * The box itself is not asserted here: which slots an Export step shows
     * depends on what the order needs (a sticker, a back pocket), so a test
     * fixture ends up asserting the fixture. What decides the address is
     * ServerIp::ipForUser, and that is what the two tests below pin down.
     */

    public function test_working_from_the_office_refreshes_the_stored_address(): void
    {
        $artist = User::factory()->create([
            'job_role' => User::JOB_ARTIST, 'is_active' => true,
            'last_login_ip' => '192.168.150.200',
        ]);

        // Any request from an office address is proof of where they are now —
        // it used to be written only at sign-in, so it went stale.
        $this->actingAs($artist)
            ->withServerVariables(['REMOTE_ADDR' => '192.168.150.114'])
            ->get('/dashboard');

        $this->assertSame('192.168.150.114', $artist->fresh()->last_login_ip);
    }

    public function test_the_tunnel_does_not_overwrite_it_with_its_own_address(): void
    {
        $artist = User::factory()->create([
            'job_role' => User::JOB_ARTIST, 'is_active' => true,
            'last_login_ip' => '192.168.150.114',
        ]);

        $this->actingAs($artist)
            ->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
            ->get('/dashboard');

        $this->assertSame('192.168.150.114', $artist->fresh()->last_login_ip,
            'the tunnel is not a place, so it must not replace where they work');
    }
}
