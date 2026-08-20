<?php

namespace Tests\Feature;

use App\Models\JobOrder;
use App\Models\OrderDocument;
use App\Models\ProductionOrder;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * One job order walked from the enquiry to the client collecting it, through
 * the real screens and the real people: agent, artist, leader, floor, finance.
 *
 * Every step goes through HTTP the way a person would do it, so this fails if
 * any link in the chain breaks — not just if a model method breaks.
 */
class WholePipelineTest extends TestCase
{
    use RefreshDatabase;

    private array $staff = [];

    private ProductionOrder $order;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        foreach ([
            'sales' => User::ROLE_SALES,
            'leader' => User::ROLE_LEADER,
            'finance' => User::ROLE_FINANCE,
            'artist' => User::JOB_ARTIST,
            'supply' => User::JOB_SUPPLY_CHAIN,
        ] as $key => $role) {
            $this->staff[$key] = User::factory()->create(['job_role' => $role, 'is_active' => true]);
        }

        // Floor roles, one person per station family.
        foreach (['Printer', 'Raw materials', 'Heat press', 'Manual cutting', 'Pairing',
            'Sewing', 'Quality control', 'Inventory', 'Mover', 'Embroidery',
            'Laser cutting', 'Roller press', 'Small press', 'Cap press'] as $role) {
            $this->staff[$role] = User::factory()->create(['job_role' => $role, 'is_active' => true]);
        }

        // Artists are only handed work when marked present.
        foreach ($this->staff as $person) {
            \App\Models\Attendance::create([
                'user_id' => $person->id,
                'date' => now()->toDateString(),
                'status' => 'present',
                'set_by' => $this->staff['leader']->id,
            ]);
        }
    }

    /** Every open task for a team, whoever it landed on. */
    private function tasksAt(string $department)
    {
        return $this->order->fresh()->tasks()
            ->where('department', $department)
            ->whereNotIn('status', ['complete', 'cancelled'])
            ->get();
    }

    /** Who signs a step off: the client's account officer, or the leader. */
    private function approverFor(Task $task): User
    {
        return $task->approver_role === 'sales'
            ? $this->staff['sales']
            : $this->staff['leader'];
    }

    /** The station a department is worked at, if it is floor work. */
    private function stationFor(string $department): ?string
    {
        foreach (\App\Services\Stations::all() as $key => $station) {
            if (in_array($department, $station['departments'], true)) {
                return $key;
            }
        }

        return null;
    }

    /**
     * Floor work is run from the station board, not a task list: someone takes
     * the machine, types their name, and marks it done when the run finishes.
     */
    private function runAtStation(Task $task, string $station): void
    {
        $operator = $this->staff['leader'];   // leaders can take any station

        $this->actingAs($operator)->post('/stations/start', [
            'station' => $station,
            'operator_name' => 'Jully',
            'production_order_id' => $this->order->id,
        ])->assertRedirect();

        $session = \App\Models\StationSession::where('station', $station)
            ->whereNull('ended_at')->latest('id')->firstOrFail();

        $this->actingAs($operator)
            ->post("/station-sessions/{$session->id}/end", ['end_reason' => 'done'])
            ->assertRedirect();
    }

    /** Work a step the way its owner would: start, hand in, then get it approved. */
    private function workStep(Task $task, bool $approve = true): void
    {
        $worker = $task->assignee ?? $this->staff['leader'];

        // A released step with nobody on it is floor work — run it at its machine.
        if (in_array($task->status, ['ready', 'in_progress'], true)
            && ! $task->usesFilePath()
            && ($station = $this->stationFor($task->department)) !== null) {
            $this->runAtStation($task, $station);
            $task->refresh();

            if ($task->status === 'for_checking' && $approve) {
                // The sample the client sees is signed off by their account
                // officer; everything else by the leader.
                $this->actingAs($this->approverFor($task))
                    ->post("/tasks/{$task->id}/approve", ['operator_name' => 'Rowena'])->assertRedirect();
            }

            return;
        }

        if ($task->status === 'ready') {
            $this->actingAs($worker)->post("/my-tasks/{$task->id}/start")->assertRedirect();
        }

        $task->refresh();

        if (in_array($task->status, ['in_progress', 'revision_required'], true)) {
            $payload = [];

            if ($task->usesFilePath()) {
                foreach ($task->fileSlots() as $key => $label) {
                    $payload["paths[$key]"] = '\\\\192.168.150.240\\Designs\\'.$task->id.'-'.$key.'.tif';
                }
                $payload = ['paths' => collect($task->fileSlots())
                    ->mapWithKeys(fn ($l, $k) => [$k => '\\\\192.168.150.240\\Designs\\'.$task->id.'.tif'])
                    ->all()];
            } elseif ($task->fileSlots() !== []) {
                foreach ($task->fileSlots() as $key => $label) {
                    $payload[$key] = UploadedFile::fake()->image($key.'.jpg');
                }
            }

            $this->actingAs($worker)
                ->post("/my-tasks/{$task->id}/submit", $payload)
                ->assertRedirect();
        }

        $task->refresh();

        if ($task->status === 'for_checking' && $approve) {
            $this->actingAs($this->approverFor($task))
                ->post("/tasks/{$task->id}/approve", ['operator_name' => 'Rowena'])->assertRedirect();
        }
    }

    /** Push every currently-open step forward until the stage clears. */
    private function clearStage(int $stage, int $guard = 40): void
    {
        while ($guard-- > 0) {
            $open = $this->order->fresh()->tasks()
                ->where('stage', $stage)
                ->whereNotIn('status', ['complete', 'cancelled'])
                ->get();

            if ($open->isEmpty()) {
                return;
            }

            $before = $open->pluck('status', 'id');

            foreach ($open as $task) {
                $this->workStep($task);
            }

            $after = $this->order->fresh()->tasks()->where('stage', $stage)
                ->whereNotIn('status', ['complete', 'cancelled'])->pluck('status', 'id');

            // Nothing moved — the pipeline is stuck, and silently looping would
            // hide that. Fail with the detail instead.
            if ($before->toArray() === $after->toArray()) {
                $this->fail("Stage $stage is stuck: ".$after->map(
                    fn ($s, $id) => "task $id is $s"
                )->join('; '));
            }
        }

        $this->fail("Stage $stage did not finish within the guard.");
    }

    public function test_a_job_goes_from_enquiry_to_the_client_collecting_it(): void
    {
        // ---- 1. The enquiry -------------------------------------------------
        $this->actingAs($this->staff['sales'])->post('/orders', [
            'order_number' => 'IC2026-05500',
            'client_name' => 'Maria',
            'client_last_name' => 'Santos',
            'client_contact' => '0917-412-8890',
            'client_office_address' => 'Sto. Rosario St., Angeles City',
            'client_delivery_address' => 'Same as office',
            'client_company' => 'Angeles Riders Club',
            'due_date' => now()->addWeeks(4)->toDateString(),
            'product_type' => 'round_neck',
            'sizes' => ['S' => 6, 'M' => 10, 'L' => 8, 'XL' => 4],
            'rush' => 1,
            'rush_fee' => 2500,
            'vat_inclusive' => 1,
        ])->assertRedirect();

        $this->order = ProductionOrder::where('order_number', 'IC2026-05500')->firstOrFail();
        $this->assertSame(28, $this->order->quantity);
        $this->assertSame('Maria Santos', $this->order->customer_name);
        $this->assertTrue((bool) $this->order->rush);

        // ---- 2. Brief to the artist, layout worked and client-approved ------
        $this->actingAs($this->staff['sales'])
            ->post("/orders/{$this->order->id}/send-for-layout", [
                'reference_note' => 'Club logo front, rider name at the back.',
            ])->assertRedirect();

        $this->assertTrue($this->order->fresh()->layoutReleased(), 'the layout never reached an artist');

        $this->clearStage(ProductionOrder::STAGE_LAYOUT);
        $this->assertTrue($this->order->fresh()->layoutApproved());

        // ---- 3. Downpayment, then the job order is sent ---------------------
        $total = (float) $this->order->fresh()->total_price;

        // The account officer collects the money; finance's own screens are
        // read-only reporting.
        $this->actingAs($this->staff['sales'])
            ->post("/orders/{$this->order->id}/payment", [
                'portion' => 'half',
                'method' => 'GCash',
                'reference' => 'GC-778812',
                // No payment is recorded without a picture of the proof.
                'proof' => UploadedFile::fake()->image('gcash.jpg'),
            ])->assertRedirect();

        $this->assertTrue($this->order->fresh()->hasDownpayment(), 'the downpayment was not recorded');

        $this->actingAs($this->staff['sales'])
            ->post("/job-orders/{$this->order->id}/update", [
                'print_type' => 'full_sublimation',
                'printer' => 'atexco',
                'fabric' => 'Dri-fit micro mesh',
                'neck' => 'Ribbed collar',
                'packaging' => 'One piece per plastic',
            ])->assertRedirect();

        // The artist can't build the mockup without the client's reference.
        $this->actingAs($this->staff['sales'])
            ->post("/job-orders/{$this->order->id}/reference", [
                'reference_files' => [UploadedFile::fake()->image('client-peg.jpg')],
                'kind' => 'peg',
            ])->assertRedirect();

        $this->actingAs($this->staff['sales'])
            ->post("/job-orders/{$this->order->id}/send")->assertRedirect();

        $this->assertSame('sent_to_artist', $this->order->fresh()->jobOrder->status);

        // ---- 4. Mockup + template, approved as a package --------------------
        // Both are handed in, then the leader signs off the pair in one go —
        // that's the approve-package button, not two separate approvals.
        foreach ($this->tasksAt('Final mockup')->merge($this->tasksAt('Production template')) as $task) {
            $this->workStep($task, approve: false);
        }

        $this->actingAs($this->staff['leader'])
            ->post("/orders/{$this->order->id}/approve-package")->assertRedirect();

        $this->assertTrue(
            $this->order->fresh()->tasks()->where('stage', 2)->get()
                ->every(fn ($t) => $t->status === 'complete'),
            'the design package did not clear'
        );

        // ---- 5. The production line ----------------------------------------
        $stages = $this->order->fresh()->tasks()
            ->where('stage', '>', 2)->distinct()->orderBy('stage')->pluck('stage');

        $this->assertNotEmpty($stages, 'no production stages were built');

        // The client settles up before anything is handed over — the release
        // step refuses to close on an outstanding balance.
        $this->actingAs($this->staff['sales'])
            ->post("/orders/{$this->order->id}/payment", [
                'portion' => 'balance',
                'method' => 'Cash',
                'proof' => UploadedFile::fake()->image('cash-receipt.jpg'),
            ])->assertRedirect();

        $this->assertEqualsWithDelta(0, (float) $this->order->fresh()->balance(), 0.01, 'the order did not settle');

        foreach ($stages as $stage) {
            $this->clearStage($stage);
        }

        // ---- 6. Finished ----------------------------------------------------
        $this->order = $this->order->fresh();

        $this->assertSame('complete', $this->order->status, 'the order never reached complete');
        $this->assertNotNull($this->order->completed_at);
        $this->assertSame(
            0,
            $this->order->tasks()->whereNotIn('status', ['complete', 'cancelled'])->count(),
            'the order completed with steps still open'
        );

        // ---- 7. The paperwork ------------------------------------------------
        $this->actingAs($this->staff['sales'])
            ->get("/orders/{$this->order->id}/document/pq")->assertOk();

        $defaults = OrderDocument::defaultsFor($this->order->fresh(), 'pq');
        $rushLine = collect($defaults['items'])->firstWhere('description', 'Rush fee');

        $this->assertNotNull($rushLine, 'the rush fee is missing from the quotation');
        $this->assertSame('Maria Santos', $defaults['fields']['bill_name']);
        $this->assertNotNull($defaults['fields']['total_vat'], 'a VAT order should carry VAT on the sheet');
    }

    public function test_every_page_of_a_finished_job_still_opens(): void
    {
        $this->test_a_job_goes_from_enquiry_to_the_client_collecting_it();

        $id = $this->order->id;

        $broken = [];

        // Each page is opened by the role that actually owns it — the client
        // paperwork belongs to the account officer, the rest to the leader.
        $pages = [
            'leader' => [
                'order' => "/orders/$id",
                // The job order sheet and the mockup page are one tech pack
                // now; the mockup URL redirects to it, so every old link still
                // lands somewhere useful rather than 404ing.
                'tech pack' => "/orders/$id/job-order",
                'package' => "/orders/$id/package",
                'messages' => "/messages/$id",
                'orders list' => '/orders',
                'dashboard' => '/dashboard',
                'calendar' => '/calendar',
                'stations' => '/stations',
                'approvals' => '/approvals',
                'products' => '/products',
                'books' => '/books',
                'finance' => '/finance',
                'material requests' => '/material-requests',
                'inventory' => '/inventory',
            ],
            'sales' => [
                'quotation' => "/orders/$id/document/pq",
                'receipt' => "/orders/$id/document/dr",
                'order (agent view)' => "/orders/$id",
            ],
        ];

        foreach ($pages as $role => $urls) {
            foreach ($urls as $what => $url) {
                $status = $this->actingAs($this->staff[$role])->get($url)->status();

                if ($status !== 200) {
                    $broken[] = "$what ($url) returned $status for $role";
                }
            }
        }

        $this->assertSame([], $broken, "pages broken after the job finished:\n".implode("\n", $broken));
    }
}
