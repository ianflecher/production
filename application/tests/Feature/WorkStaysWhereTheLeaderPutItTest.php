<?php

namespace Tests\Feature;

use App\Models\ProductionOrder;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * An artist's queue stays where it was put.
 *
 * It used to follow whoever was at a desk: signing off passed on the steps you
 * had not started, signing back in took them home, and the bench levelled
 * itself so nobody sat idle beside somebody buried. It was well meant and the
 * shop asked for it to stop — a queue that rearranges itself between one look
 * and the next is a queue nobody can plan a day around, and work arrived on
 * people who had never been told about it.
 *
 * Moving work is the leader's to do now, through tasks.assign. Nothing in
 * signing in or out may touch an assignment.
 */
class WorkStaysWhereTheLeaderPutItTest extends TestCase
{
    use RefreshDatabase;

    private ProductionOrder $order;

    protected function setUp(): void
    {
        parent::setUp();

        $sales = User::factory()->create(['job_role' => User::ROLE_SALES, 'is_active' => true]);

        $this->order = ProductionOrder::create([
            'order_number' => 'IC2026-BENCH', 'customer_name' => 'Bench Co',
            'product_type' => 'round_neck', 'quantity' => 10,
            'due_date' => now()->addWeeks(3), 'created_by' => $sales->id, 'status' => 'active',
        ]);
    }

    /** An artist who is signed in and marked present. */
    private function artistOnDuty(string $name): User
    {
        $artist = User::factory()->create([
            'job_role' => User::JOB_ARTIST, 'is_active' => true, 'name' => $name,
        ]);

        $artist->attendances()->create(['date' => today(), 'status' => 'present']);

        DB::table('sessions')->insert([
            'id' => 'sess-'.$artist->id,
            'user_id' => $artist->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'test',
            'payload' => '',
            'last_activity' => now()->getTimestamp(),
        ]);

        return $artist;
    }

    private function step(User $artist, string $status, int $sequence = 1): Task
    {
        return Task::create([
            'production_order_id' => $this->order->id,
            'department' => 'Layout', 'sequence' => $sequence, 'stage' => 1,
            'team' => User::JOB_ARTIST, 'assigned_to' => $artist->id, 'status' => $status,
        ]);
    }

    public function test_signing_off_leaves_the_work_where_it_is(): void
    {
        $leaving = $this->artistOnDuty('Cristal');
        $this->artistOnDuty('Mick');           // somebody who would have received it
        $step = $this->step($leaving, 'ready');

        Auth::login($leaving);
        Auth::logout();

        $this->assertSame($leaving->id, $step->fresh()->assigned_to,
            'signing off must not hand the step to anybody');
        $this->assertNull($step->fresh()->passed_from);
    }

    public function test_signing_back_in_takes_nothing_from_anybody(): void
    {
        $returning = $this->artistOnDuty('Cristal');
        $busy = $this->artistOnDuty('Mick');

        // Mick is holding several; Cristal is holding none. The bench would
        // once have levelled itself the moment she signed in.
        $mine = collect([2, 3, 4])->map(fn ($i) => $this->step($busy, 'ready', $i));

        Auth::login($returning);

        foreach ($mine as $step) {
            $this->assertSame($busy->id, $step->fresh()->assigned_to,
                'a returning artist does not take work off somebody else');
        }
    }

    public function test_the_leader_is_the_one_who_moves_it(): void
    {
        $from = $this->artistOnDuty('Cristal');
        $to = $this->artistOnDuty('Mick');
        $step = $this->step($from, 'ready');

        $leader = User::factory()->create(['job_role' => User::JOB_ARTIST_LEAD, 'is_active' => true]);

        $this->actingAs($leader)
            ->post(route('tasks.assign', $step), ['assigned_to' => $to->id])
            ->assertRedirect();

        $this->assertSame($to->id, $step->fresh()->assigned_to,
            'what the automation used to do, the leader still can');
    }

}
