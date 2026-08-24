<?php

namespace Tests\Feature;

use App\Models\ProductionOrder;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A helper used in one <script> has to exist in that <script>.
 *
 * Each inline block is its own scope. A function declared in one is invisible
 * to the next, and calling it there throws on the first use — which takes the
 * REST of that block with it. The tech pack draws its leader lines in one
 * block and drags them in another; two helpers were added to the first and
 * called from the second, and every drag handler on the sheet died silently.
 * The lines still drew, because drawing was in the block that still worked, so
 * it looked like the pins had merely come loose.
 *
 * InlineScriptsParseTest cannot catch this: the code parses perfectly. It is
 * only wrong at run time, in a block nobody was watching.
 *
 * The rule checked here is the one that would have caught it: a block that
 * CALLS one of the shared helpers must also define it, or take it off window.
 */
class InlineScriptsShareTheirHelpersTest extends TestCase
{
    use RefreshDatabase;

    /** The helpers the pack passes between its script blocks. */
    private const SHARED = ['setPin', 'readPin'];

    /** @return array<int, string> the blocks that call a helper they do not have */
    private function orphanedCalls(string $html): array
    {
        preg_match_all('#<script\b[^>]*>(.*?)</script>#is', $html, $m);

        $bad = [];

        foreach ($m[1] as $i => $code) {
            foreach (self::SHARED as $name) {
                // Called here?
                if (! preg_match('/\b'.$name.'\s*\(/', $code)) {
                    continue;
                }

                // Then it must be declared here, or lifted off window here.
                $declared = preg_match('/function\s+'.$name.'\s*\(/', $code)
                    || preg_match('/\b(?:var|let|const)\s+'.$name.'\s*=/', $code);

                if (! $declared) {
                    $bad[] = 'script block '.($i + 1).' calls '.$name.'() without having it';
                }
            }
        }

        return $bad;
    }

    public function test_the_check_notices_a_helper_used_across_two_blocks(): void
    {
        // The exact shape of the fault, so this test is known to bite.
        $broken = '<script>function setPin(el, x, y) { el.dataset.x = x; }</script>'
            .'<script>setPin(document.body, 1, 2);</script>';

        $this->assertNotEmpty($this->orphanedCalls($broken));
    }

    public function test_the_check_passes_a_block_that_takes_the_helper_over(): void
    {
        $fine = '<script>function setPin(el, x, y) { el.dataset.x = x; } window.tpSetPin = setPin;</script>'
            .'<script>var setPin = window.tpSetPin; setPin(document.body, 1, 2);</script>';

        $this->assertSame([], $this->orphanedCalls($fine));
    }

    public function test_the_packs_scripts_all_have_the_helpers_they_use(): void
    {
        $sales = User::factory()->create(['job_role' => User::ROLE_SALES, 'is_active' => true]);
        $artist = User::factory()->create(['job_role' => User::JOB_ARTIST, 'is_active' => true]);

        $order = ProductionOrder::create([
            'order_number' => 'IC2026-SCOPE', 'customer_name' => 'Scope Co',
            'product_type' => 'round_neck', 'quantity' => 12,
            'due_date' => now()->addWeek(), 'created_by' => $sales->id, 'status' => 'active',
        ]);

        $order->jobOrder()->create([
            'status' => 'sent_to_artist', 'created_by' => $sales->id,
            'print_type' => 'dtf', 'printer' => 'dtf_printer',
        ]);

        $task = Task::create([
            'production_order_id' => $order->id, 'department' => 'Tech pack',
            'sequence' => 3, 'stage' => 2, 'status' => 'ready',
            'team' => User::JOB_ARTIST, 'assigned_to' => $artist->id,
        ]);

        // The artist's copy is the one with the editing script on it.
        $html = $this->actingAs($artist)
            ->get("/my-tasks/{$task->id}/job-order")
            ->assertOk()
            ->getContent();

        $this->assertSame(
            [],
            $this->orphanedCalls($html),
            'a script block on the pack calls a helper that is not in scope there'
        );
    }

    public function test_the_drawing_script_hands_its_helpers_out(): void
    {
        $sales = User::factory()->create(['job_role' => User::ROLE_SALES, 'is_active' => true]);
        $artist = User::factory()->create(['job_role' => User::JOB_ARTIST, 'is_active' => true]);

        $order = ProductionOrder::create([
            'order_number' => 'IC2026-SHARE', 'customer_name' => 'Share Co',
            'product_type' => 'round_neck', 'quantity' => 12,
            'due_date' => now()->addWeek(), 'created_by' => $sales->id, 'status' => 'active',
        ]);

        $order->jobOrder()->create([
            'status' => 'sent_to_artist', 'created_by' => $sales->id,
            'print_type' => 'dtf', 'printer' => 'dtf_printer',
        ]);

        $task = Task::create([
            'production_order_id' => $order->id, 'department' => 'Tech pack',
            'sequence' => 3, 'stage' => 2, 'status' => 'ready',
            'team' => User::JOB_ARTIST, 'assigned_to' => $artist->id,
        ]);

        $html = $this->actingAs($artist)
            ->get("/my-tasks/{$task->id}/job-order")
            ->assertOk()
            ->getContent();

        // The way across the scope boundary, however the blocks are arranged.
        $this->assertStringContainsString('window.tpSetPin', $html);
        $this->assertStringContainsString('window.tpReadPin', $html);
    }
}
