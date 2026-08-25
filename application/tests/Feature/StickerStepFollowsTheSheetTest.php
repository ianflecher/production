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
 * to live on is gone. It is part of the client's order, so it belongs to the
 * account officer's half of the sheet, and the step the floor works follows
 * what they write there.
 *
 * The rule: a name means a sticker; a placeholder like "n/a" means somebody
 * saying there is none.
 */
class StickerStepFollowsTheSheetTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: User, 1: ProductionOrder} */
    private function packReadyForTheOfficer(): array
    {
        $sales = User::factory()->create(['job_role' => User::ROLE_SALES, 'is_active' => true]);
        $artist = User::factory()->create(['job_role' => User::JOB_ARTIST, 'is_active' => true]);

        $order = ProductionOrder::create([
            'order_number' => 'IC2026-STICK', 'customer_name' => 'Sticker Co',
            'product_type' => 'round_neck', 'quantity' => 30,
            'due_date' => now()->addWeeks(2), 'created_by' => $sales->id, 'status' => 'active',
            // The column defaults to TRUE, so an order nobody has said anything
            // about arrives already wanting a sticker. Said plainly here: this
            // order has not asked for one, which is what these tests are about.
            'needs_sticker' => false,
        ]);

        $order->jobOrder()->create([
            'status' => 'draft', 'created_by' => $sales->id,
            'print_type' => 'dtf', 'printer' => 'dtf_printer', 'fabric' => 'Cotton blend',
        ]);

        $order->buildPipeline([], null);

        // The officer only reaches the pack once the mockup is approved.
        $order->tasks()->where('department', 'Final mockup')->update([
            'status' => 'complete', 'assigned_to' => $artist->id, 'approved_at' => now(),
        ]);

        return [$sales, $order->refresh()];
    }

    private function stickerStep(ProductionOrder $order): ?Task
    {
        return $order->tasks()->where('department', 'Sticker')->first();
    }

    /** @param array<string, string> $extra */
    private function officerSaves(User $officer, ProductionOrder $order, array $extra): void
    {
        // Print type, printer and fabric are required on that form, so they
        // travel with every save — the sheet cannot be filed without them.
        $this->actingAs($officer)
            ->post("/job-orders/{$order->id}/update", $extra + [
                'print_type' => 'dtf',
                'printer' => 'dtf_printer',
                'fabric' => 'Cotton blend',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();
    }

    public function test_naming_a_sticker_on_the_pack_puts_the_step_on_the_floor(): void
    {
        [$officer, $order] = $this->packReadyForTheOfficer();

        $this->assertNull($this->stickerStep($order), 'no sticker was asked for yet');

        $this->officerSaves($officer, $order, ['free_logo_sticker' => 'IC sticker']);

        $order->refresh();

        $this->assertTrue((bool) $order->needs_sticker);
        $this->assertNotNull($this->stickerStep($order), 'the floor has no step for the sticker the pack asks for');
    }

    public function test_saying_there_is_none_does_not_order_one(): void
    {
        [$officer, $order] = $this->packReadyForTheOfficer();

        $this->officerSaves($officer, $order, ['free_logo_sticker' => 'N/A']);

        $this->assertFalse((bool) $order->refresh()->needs_sticker);
        $this->assertNull($this->stickerStep($order));
    }

    public function test_clearing_the_row_takes_the_step_away_again(): void
    {
        [$officer, $order] = $this->packReadyForTheOfficer();

        $this->officerSaves($officer, $order, ['free_logo_sticker' => 'IC sticker']);
        $this->assertNotNull($this->stickerStep($order->refresh()));

        $this->officerSaves($officer, $order, ['free_logo_sticker' => '']);

        $this->assertFalse((bool) $order->refresh()->needs_sticker);
        $this->assertNull($this->stickerStep($order), 'a sticker nobody asked for is still on the floor');
    }

    public function test_the_artist_cannot_order_a_sticker_from_their_half(): void
    {
        // The row is the officer's. An artist posting it by hand changes
        // nothing — the lock on the sheet is a lock in the save as well.
        [$officer, $order] = $this->packReadyForTheOfficer();
        $artist = User::factory()->create(['job_role' => User::JOB_ARTIST, 'is_active' => true]);

        $order->jobOrder->update(['status' => 'sent_to_artist']);
        $task = $order->tasks()->where('department', 'Tech pack')->firstOrFail();
        $task->update(['status' => 'ready', 'assigned_to' => $artist->id]);

        $this->actingAs($artist)->post("/my-tasks/{$task->id}/tech-pack", [
            'free_logo_sticker' => 'Sticker the artist wanted',
        ])->assertRedirect();

        $this->assertNull($order->refresh()->jobOrder->free_logo_sticker);
        $this->assertFalse((bool) $order->needs_sticker);
        $this->assertNull($this->stickerStep($order));
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
