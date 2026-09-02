<?php

namespace Tests\Feature;

use App\Models\ProductionOrder;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A sticker is ordered by writing one on the pack.
 *
 * "Sticker / extra" is a row on the TECH PACK now — the job order sheet it used
 * to live on is gone. The assigned artist owns every manual Tech Pack row, and
 * the step the floor works follows what the artist writes there.
 *
 * The rule: a name means a sticker; a placeholder like "n/a" means somebody
 * saying there is none.
 */
class StickerStepFollowsTheSheetTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: User, 1: ProductionOrder} */
    private function packReadyForTheArtist(): array
    {
        $sales = User::factory()->create(['job_role' => User::ROLE_SALES, 'is_active' => true]);
        $artist = User::factory()->create(['job_role' => User::JOB_ARTIST, 'is_active' => true]);

        $order = ProductionOrder::create([
            'order_number' => 'IC2026-STICK', 'customer_name' => 'Sticker Co',
            'product_type' => 'round_neck', 'quantity' => 30,
            'due_date' => now()->addWeeks(2), 'created_by' => $sales->id, 'status' => 'active',
            // Said plainly, though it is also the default now: this order has
            // not asked for a sticker, which is what these tests are about.
            'needs_sticker' => false,
        ]);

        $order->jobOrder()->create([
            'status' => 'sent_to_artist', 'created_by' => $sales->id,
            'print_type' => 'dtf', 'printer' => 'dtf_printer', 'fabric' => 'Cotton blend',
        ]);

        $order->buildPipeline([], null);

        // The officer only reaches the pack once the mockup is approved.
        $order->tasks()->where('department', 'Final mockup')->update([
            'status' => 'complete', 'assigned_to' => $artist->id, 'approved_at' => now(),
        ]);
        $order->tasks()->where('department', 'Tech pack')->update([
            'status' => 'in_progress', 'assigned_to' => $artist->id, 'approver_role' => 'sales',
        ]);

        return [$artist, $order->refresh()];
    }

    private function stickerStep(ProductionOrder $order): ?Task
    {
        return $order->tasks()->where('department', 'Sticker')->first();
    }

    /** @param array<string, string> $extra */
    private function artistSaves(User $artist, ProductionOrder $order, array $extra): void
    {
        $task = $order->tasks()->where('department', 'Tech pack')->firstOrFail();

        $this->actingAs($artist)
            ->post(route('tasks.tech-pack', $task), $extra + [
                'print_type' => 'dtf',
                'printer' => 'dtf_printer',
                'fabric' => 'Cotton blend',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();
    }

    public function test_naming_a_sticker_on_the_pack_puts_the_step_on_the_floor(): void
    {
        [$artist, $order] = $this->packReadyForTheArtist();

        $this->assertNull($this->stickerStep($order), 'no sticker was asked for yet');

        $this->artistSaves($artist, $order, ['free_logo_sticker' => 'IC sticker']);

        $order->refresh();

        $this->assertTrue((bool) $order->needs_sticker);
        $this->assertNotNull($this->stickerStep($order), 'the floor has no step for the sticker the pack asks for');
    }

    public function test_saying_there_is_none_does_not_order_one(): void
    {
        [$artist, $order] = $this->packReadyForTheArtist();

        $this->artistSaves($artist, $order, ['free_logo_sticker' => 'N/A']);

        $this->assertFalse((bool) $order->refresh()->needs_sticker);
        $this->assertNull($this->stickerStep($order));
    }

    public function test_clearing_the_row_takes_the_step_away_again(): void
    {
        [$artist, $order] = $this->packReadyForTheArtist();

        $this->artistSaves($artist, $order, ['free_logo_sticker' => 'IC sticker']);
        $this->assertNotNull($this->stickerStep($order->refresh()));

        $this->artistSaves($artist, $order, ['free_logo_sticker' => '']);

        $this->assertFalse((bool) $order->refresh()->needs_sticker);
        $this->assertNull($this->stickerStep($order), 'a sticker nobody asked for is still on the floor');
    }

    public function test_the_assigned_artist_can_order_a_sticker_from_the_complete_pack(): void
    {
        [$artist, $order] = $this->packReadyForTheArtist();

        $this->artistSaves($artist, $order, ['free_logo_sticker' => 'IC woven sticker']);

        $this->assertSame('IC woven sticker', $order->refresh()->jobOrder->free_logo_sticker);
        $this->assertTrue((bool) $order->needs_sticker);
        $this->assertNotNull($this->stickerStep($order));
    }

    public function test_an_order_nobody_has_spoken_about_wants_no_sticker(): void
    {
        // The column used to default to TRUE, so a job asked the supply desk
        // for a sticker before anybody had said a word about it. A blank row
        // means there is none.
        $sales = User::factory()->create(['job_role' => User::ROLE_SALES, 'is_active' => true]);

        $order = ProductionOrder::create([
            'order_number' => 'IC2026-QUIET', 'customer_name' => 'Quiet Co',
            'product_type' => 'round_neck', 'quantity' => 10,
            'due_date' => now()->addWeek(), 'created_by' => $sales->id, 'status' => 'active',
        ]);

        $this->assertFalse((bool) $order->fresh()->needs_sticker);

        $order->jobOrder()->create(['status' => 'draft', 'created_by' => $sales->id]);
        $order->refresh()->buildPipeline([], null);

        $this->assertNull(
            $this->stickerStep($order->refresh()),
            'a sticker step for a job that never asked for one'
        );
    }

    public function test_the_rule_reads_the_words_the_shop_actually_types(): void
    {
        foreach (['IC sticker', 'Woven label', 'IC STICKER'] as $yes) {
            $this->assertTrue(ProductionOrder::namesASticker($yes), $yes.' names a sticker');
        }

        foreach (['', '   ', 'n/a', 'N/A', 'none', 'None', '-', 'wala', 'x'] as $no) {
            $this->assertFalse(ProductionOrder::namesASticker($no), $no.' does not name a sticker');
        }
    }
}
