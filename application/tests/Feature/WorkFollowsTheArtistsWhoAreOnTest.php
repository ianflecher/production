<?php

namespace Tests\Feature;

use App\Models\ProductionOrder;
use App\Models\Task;
use App\Models\User;
use App\Services\ArtistBench;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * An artist's queue follows the artists who are actually at a desk.
 *
 * Their work is handed to a named person, so a queue sitting with somebody who
 * has gone home is a queue nobody is drawing. Signing off passes on what they
 * had NOT started — anything in progress stays with them, because a part-drawn
 * layout in somebody else's hands is work done twice.
 *
 * It is a loan. Signing back in takes it back, unless whoever received it has
 * started or finished it: then it stays where the work is. After that the
 * bench is levelled, so nobody sits idle beside somebody buried.
 */
class WorkFollowsTheArtistsWhoAreOnTest extends TestCase
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

    private function signOff(User $artist): void
    {
        DB::table('sessions')->where('user_id', $artist->id)->delete();
    }

    private function step(User $artist, string $status, int $sequence = 1): Task
    {
        return Task::create([
            'production_order_id' => $this->order->id,
            'department' => 'Layout', 'sequence' => $sequence, 'stage' => 1,
            'team' => User::JOB_ARTIST, 'assigned_to' => $artist->id, 'status' => $status,
        ]);
    }

    public function test_unstarted_work_passes_to_whoever_is_still_on(): void
    {
        $leaving = $this->artistOnDuty('Maru');
        $staying = $this->artistOnDuty('Mick');

        $step = $this->step($leaving, 'ready');

        $this->signOff($leaving);
        ArtistBench::handOver($leaving);

        $this->assertSame($staying->id, $step->fresh()->assigned_to);
        $this->assertSame($leaving->id, $step->fresh()->passed_from, 'it forgot whose it was');
    }

    public function test_work_they_had_started_stays_with_them(): void
    {
        // A part-drawn layout in somebody else's hands is work done twice.
        $leaving = $this->artistOnDuty('Maru');
        $this->artistOnDuty('Mick');

        $started = $this->step($leaving, 'in_progress');

        $this->signOff($leaving);
        ArtistBench::handOver($leaving);

        $this->assertSame($leaving->id, $started->fresh()->assigned_to);
    }

    public function test_with_nobody_left_on_the_work_waits(): void
    {
        $leaving = $this->artistOnDuty('Maru');
        $step = $this->step($leaving, 'ready');

        $this->signOff($leaving);
        ArtistBench::handOver($leaving);

        $this->assertSame($leaving->id, $step->fresh()->assigned_to, 'it was given to nobody');
        $this->assertNull($step->fresh()->passed_from);
    }

    public function test_signing_back_in_takes_it_home(): void
    {
        $leaving = $this->artistOnDuty('Maru');
        $staying = $this->artistOnDuty('Mick');

        $step = $this->step($leaving, 'ready');

        $this->signOff($leaving);
        ArtistBench::handOver($leaving);
        $this->assertSame($staying->id, $step->fresh()->assigned_to);

        ArtistBench::welcomeBack($leaving);

        $this->assertSame($leaving->id, $step->fresh()->assigned_to);
        $this->assertNull($step->fresh()->passed_from, 'it is theirs again, not on loan');
    }

    public function test_what_the_other_artist_started_stays_with_them(): void
    {
        $leaving = $this->artistOnDuty('Maru');
        $staying = $this->artistOnDuty('Mick');

        $step = $this->step($leaving, 'ready');

        $this->signOff($leaving);
        ArtistBench::handOver($leaving);

        // Mick has begun drawing it.
        $step->fresh()->update(['status' => 'in_progress']);

        ArtistBench::welcomeBack($leaving);

        $this->assertSame($staying->id, $step->fresh()->assigned_to, 'work in progress was taken back');
        $this->assertNull($step->fresh()->passed_from, 'it is no longer a loan');
    }

    public function test_the_bench_levels_when_somebody_comes_back(): void
    {
        // Six on one desk and none on the other is not a bench, it is a queue
        // with a spectator.
        $busy = $this->artistOnDuty('Mick');
        $returning = $this->artistOnDuty('Maru');

        for ($i = 1; $i <= 6; $i++) {
            $this->step($busy, 'ready', $i);
        }

        ArtistBench::welcomeBack($returning);

        $this->assertLessThanOrEqual(
            1,
            abs(ArtistBench::load($busy) - ArtistBench::load($returning)),
            'the work was not shared out'
        );
    }

    public function test_levelling_leaves_started_work_alone(): void
    {
        $busy = $this->artistOnDuty('Mick');
        $returning = $this->artistOnDuty('Maru');

        $started = $this->step($busy, 'in_progress', 1);
        for ($i = 2; $i <= 5; $i++) {
            $this->step($busy, 'ready', $i);
        }

        ArtistBench::welcomeBack($returning);

        $this->assertSame($busy->id, $started->fresh()->assigned_to, 'work in progress was moved');
    }

    public function test_somebody_absent_is_not_at_a_desk(): void
    {
        // Signed in AND marked present. A browser left open on a machine
        // nobody is at is not somebody to hand work to.
        $leaving = $this->artistOnDuty('Maru');

        $notMarked = User::factory()->create([
            'job_role' => User::JOB_ARTIST, 'is_active' => true, 'name' => 'Ghost',
        ]);
        DB::table('sessions')->insert([
            'id' => 'sess-ghost', 'user_id' => $notMarked->id, 'ip_address' => '127.0.0.1',
            'user_agent' => 'test', 'payload' => '', 'last_activity' => now()->getTimestamp(),
        ]);

        $step = $this->step($leaving, 'ready');

        $this->signOff($leaving);
        ArtistBench::handOver($leaving);

        $this->assertSame($leaving->id, $step->fresh()->assigned_to, 'work went to somebody not marked in');
    }

    public function test_signing_out_hands_over_for_real(): void
    {
        // Through the actual logout, not the service directly.
        $leaving = $this->artistOnDuty('Maru');
        $staying = $this->artistOnDuty('Mick');

        $step = $this->step($leaving, 'ready');

        $this->actingAs($leaving)->post(route('logout'));

        $this->assertSame($staying->id, $step->fresh()->assigned_to);
    }
}
