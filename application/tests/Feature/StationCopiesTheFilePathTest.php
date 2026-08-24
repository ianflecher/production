<?php

namespace Tests\Feature;

use App\Models\ProductionOrder;
use App\Models\StationSession;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The station pastes the folder; it does not retype it.
 *
 * Where the print files went is one long string of digits and back-slashes, and
 * a wrong digit looks exactly like a right one. It used to be small print
 * inside the tech pack, which is fine for reading and useless for pasting into
 * Explorer — so it gets its own bar with a button on the page the operator is
 * actually standing at.
 */
class StationCopiesTheFilePathTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: User, 1: ProductionOrder} */
    private function orderAtPrinter(): array
    {
        $sales = User::factory()->create(['job_role' => User::ROLE_SALES, 'is_active' => true]);
        // The printer, because the folder is only for the stations that open
        // those files — a sewer is holding a garment, not looking for a path.
        $printer = User::factory()->create(['job_role' => 'printer', 'is_active' => true]);

        $order = ProductionOrder::create([
            'order_number' => 'IC2026-0'.random_int(1000, 9999),
            'customer_name' => 'Seam Co',
            'product_type' => 'round_neck',
            'quantity' => 20,
            'due_date' => now()->addWeek(),
            'created_by' => $sales->id,
            'status' => 'active',
        ]);

        // A printer only gets jobs whose job order picked THAT machine.
        $order->jobOrder()->create([
            'status' => 'draft', 'created_by' => $sales->id, 'printer' => 'atexco',
        ]);

        Task::create([
            'production_order_id' => $order->id,
            'department' => 'Printer',
            'sequence' => 5,
            'stage' => 3,
            'status' => 'ready',
        ]);

        return [$printer, $order->fresh()];
    }

    private function runningOn(User $who, ProductionOrder $order, string $station = 'printer_atexco'): StationSession
    {
        $this->actingAs($who)->post('/stations/start', [
            'station' => $station,
            'operator_name' => 'Marites Bautista',
            'production_order_id' => $order->id,
        ]);

        return StationSession::where('station', $station)->whereNull('ended_at')->firstOrFail();
    }

    public function test_the_folder_the_print_files_are_in_is_there_to_copy(): void
    {
        [$printer, $order] = $this->orderAtPrinter();

        $order->techPack()->create([
            'file_location_notes' => '\\\\192.168.150.216\\FOR PRINT\\IC2026-00001',
        ]);

        $session = $this->runningOn($printer, $order);

        $this->actingAs($printer)
            ->get(route('stations.finish', $session))
            ->assertOk()
            ->assertSee('192.168.150.216')
            ->assertSee('Copy path');
    }

    public function test_the_work_sheet_document_carries_the_folder_on_its_own_page(): void
    {
        // "Open work sheet" from the station board. This is the copy that gets
        // printed and pinned up, so the folder is a page rather than a bar.
        [$printer, $order] = $this->orderAtPrinter();

        $order->techPack()->create([
            'file_location_notes' => '\\192.168.150.216\FOR PRINT\IC2026-00001',
        ]);

        // The station board links its own copy — "open work sheet" carries the
        // station it is for.
        $this->actingAs($printer)
            ->get(route('orders.package', [$order, 'for' => 'printer']))
            ->assertOk()
            ->assertSee('PRINT FILES')
            ->assertSee('192.168.150.216');
    }

    public function test_a_station_that_does_not_open_files_is_not_given_the_folder(): void
    {
        // The sewing floor has a garment in their hands, not a folder to find.
        [, $order] = $this->orderAtPrinter();

        $order->techPack()->create([
            'file_location_notes' => '\\192.168.150.216\FOR PRINT\IC2026-00001',
        ]);

        $sewer = User::factory()->create(['job_role' => 'Sewing', 'is_active' => true]);

        $this->actingAs($sewer)
            ->get(route('orders.package', [$order, 'for' => 'production']))
            ->assertOk()
            ->assertDontSee('PRINT FILES');
    }

    public function test_nothing_is_shown_before_the_artist_records_one(): void
    {
        [$printer, $order] = $this->orderAtPrinter();
        $session = $this->runningOn($printer, $order);

        $this->actingAs($printer)
            ->get(route('stations.finish', $session))
            ->assertOk()
            ->assertDontSee('Copy path');
    }
}
