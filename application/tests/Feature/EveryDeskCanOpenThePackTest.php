<?php

namespace Tests\Feature;

use App\Models\ProductionOrder;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Everyone the job passes through can read the pack it is made from.
 *
 * The pack replaced the job order sheet, and that sheet was what the floor read
 * at the machine. If a desk further down the line cannot open it, that desk is
 * working from memory — so this asks each of them by name.
 *
 * They come in by different doors, and that is deliberate. The office opens the
 * ORDER (/orders/{id}/job-order), which carries the money, the client and the
 * admin around the pack. The floor opens the DOCUMENT (/orders/{id}/package),
 * which is the pack and nothing else — no prices, no client contact, no buttons
 * that change the order.
 */
class EveryDeskCanOpenThePackTest extends TestCase
{
    use RefreshDatabase;

    private ProductionOrder $order;

    protected function setUp(): void
    {
        parent::setUp();

        $sales = User::factory()->create(['job_role' => User::ROLE_SALES, 'is_active' => true]);

        $this->order = ProductionOrder::create([
            'order_number' => 'IC2026-SEEN', 'customer_name' => 'Seen Co',
            'product_type' => 'round_neck', 'quantity' => 40,
            'due_date' => now()->addWeek(), 'created_by' => $sales->id, 'status' => 'active',
        ]);

        $this->order->jobOrder()->create([
            'status' => 'sent_to_artist', 'created_by' => $sales->id,
            'print_type' => 'dtf', 'printer' => 'dtf_printer', 'fabric' => 'Cotton blend',
            // The routing half: which press, which cutting, what it is made of.
            'fabric_press' => 'heat_press', 'press' => 'heat_press',
            'addon' => 'embroidery', 'addon_note' => 'Left sleeve',
            'raw_materials' => ['Cotton shirt blank'],
            'raw_material_quantities' => ['Cotton shirt blank' => 40],
        ]);

        $this->order->techPack()->create([
            'design_name' => 'Seen From Every Desk',
            'item_style' => 'Cotton shirt',
            'file_location_notes' => '\\\\IC-SERVER\FOR PRINT',
        ]);

        Task::create([
            'production_order_id' => $this->order->id, 'department' => 'Final mockup',
            'sequence' => 2, 'stage' => 2, 'status' => 'complete', 'approved_at' => now(),
        ]);
    }

    /** Every desk a job passes through on its way out of the shop. */
    public static function desks(): array
    {
        return [
            'leader' => [User::ROLE_LEADER],
            'artist' => [User::JOB_ARTIST],
            'raw materials' => ['Raw Materials'],
            'supply chain' => ['supply_chain'],
            'printer' => ['printer'],
            'sticker' => ['sticker'],
            'embroidery' => ['embroidery'],
            'heat press' => ['Heat Press'],
            'laser cutting' => ['Laser Cutting'],
            'pairing' => ['Pairing'],
            'sewing' => ['Sewing'],
            'quality control' => ['Quality Control'],
            'inventory' => ['Inventory'],
            'mover' => ['Mover'],
        ];
    }

    /**
     * @dataProvider desks
     */
    public function test_the_desk_can_read_the_pack(string $role): void
    {
        $who = User::factory()->create(['job_role' => $role, 'is_active' => true]);

        $this->actingAs($who)
            ->get(route('orders.package', $this->order))
            ->assertOk()
            ->assertSee('Seen From Every Desk')
            // The whole sheet, not a summary of it.
            ->assertSee('Materials and components')
            ->assertSee('Cotton blend')
            // …and the routing behind it: press, add-on, cutting, materials.
            ->assertSee('PRODUCTION DETAILS')
            ->assertSee('Cotton shirt blank');
    }

    public function test_the_printing_side_gets_the_files_page_instead_of_the_routing(): void
    {
        // A printer is told where the files are; the press and cutting belong
        // to the floor that does the pressing and cutting, and a page of it at
        // the printer is a page in the way.
        $printer = User::factory()->create(['job_role' => 'printer', 'is_active' => true]);

        $this->actingAs($printer)
            ->get(route('orders.package', [$this->order, 'for' => 'printer']))
            ->assertOk()
            ->assertSee('PRINT FILES')
            ->assertDontSee('PRODUCTION DETAILS');

        // The sewing floor's copy is the other way round.
        $sewer = User::factory()->create(['job_role' => 'Sewing', 'is_active' => true]);

        $this->actingAs($sewer)
            ->get(route('orders.package', [$this->order, 'for' => 'production']))
            ->assertOk()
            ->assertSee('PRODUCTION DETAILS')
            ->assertDontSee('PRINT FILES');
    }

    public function test_the_floor_reads_the_pack_without_the_money_around_it(): void
    {
        $printer = User::factory()->create(['job_role' => 'printer', 'is_active' => true]);

        // The order page belongs to the office: it carries the price, the
        // payments and the buttons that change the job. The floor is not shut
        // out of the PACK, only out of the admin wrapped around it.
        $this->actingAs($printer)
            ->get(route('orders.job-order', $this->order))
            ->assertForbidden();

        $this->actingAs($printer)
            ->get(route('orders.package', $this->order))
            ->assertOk();
    }

    public function test_the_office_reads_it_on_the_order_itself(): void
    {
        foreach ([User::ROLE_LEADER, 'Mover'] as $role) {
            $who = User::factory()->create(['job_role' => $role, 'is_active' => true]);

            $this->actingAs($who)
                ->get(route('orders.job-order', $this->order))
                ->assertOk()
                ->assertSee('Seen From Every Desk');
        }
    }

    public function test_an_account_officer_cannot_read_another_officers_job(): void
    {
        // The one door that is shut to an office role, and it was shut before
        // the pack existed: an order belongs to the officer who took it.
        $other = User::factory()->create(['job_role' => User::ROLE_SALES, 'is_active' => true]);

        $this->actingAs($other)
            ->get(route('orders.job-order', $this->order))
            ->assertForbidden();
    }
}
