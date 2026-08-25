<?php

namespace Tests\Feature;

use App\Models\ProductionOrder;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The artist's card grid lines up.
 *
 * Cards sit in a grid, so every card in a row is stretched to the tallest one.
 * Their contents were not stretched with them, so the progress bar, the due
 * date and the export paths each stopped wherever that card's text happened to
 * end — three cards side by side with three different baselines and dead space
 * under the short ones.
 *
 * A layout is not something a test can look at, so this pins the structure
 * that produces the alignment: the card is a column, and its footer is the
 * part pushed to the bottom.
 */
class MyTasksCardAlignmentTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    /**
     * Counted, not guessed at.
     *
     * The number used to be random_int(1000, 9999), and order_number is unique
     * — so two orders in the same test collided roughly once in nine thousand
     * tries. That is often enough to fail a suite now and then and pass on the
     * retry, which is the worst way for a test to be wrong: it teaches people
     * to run it again rather than read it.
     */
    private int $made = 0;

    /** An artist with one open step and one finished order behind them. */
    private function artistWithWork(): User
    {
        $sales = User::factory()->create(['job_role' => User::ROLE_SALES, 'is_active' => true]);
        $artist = User::factory()->create(['job_role' => User::JOB_ARTIST, 'is_active' => true]);

        foreach ([['Layout', 'ready'], ['Final mockup', 'complete']] as [$department, $status]) {
            $order = ProductionOrder::create([
                'order_number' => 'IC2026-0'.(1000 + ++$this->made),
                'customer_name' => 'Grid Co', 'product_type' => 'round_neck',
                'quantity' => 30, 'due_date' => now()->addWeeks(2),
                'created_by' => $sales->id, 'status' => 'active',
            ]);

            Task::create([
                'production_order_id' => $order->id,
                'department' => $department,
                'sequence' => ++$this->seq,
                'stage' => 1,
                'status' => $status,
                'assigned_to' => $artist->id,
                'approved_at' => $status === 'complete' ? now() : null,
            ]);
        }

        return $artist;
    }

    public function test_the_page_opens_for_an_artist(): void
    {
        $this->actingAs($this->artistWithWork())->get('/my-tasks')->assertOk();
    }

    public function test_every_card_is_a_full_height_column(): void
    {
        $html = $this->actingAs($this->artistWithWork())->get('/my-tasks')->getContent();

        $this->assertStringContainsString('mt-card', $html);
        $this->assertStringContainsString('flex-direction: column', $html);
        $this->assertStringContainsString('height: 100%', $html);
    }

    public function test_the_footer_is_pushed_to_the_bottom_of_the_card(): void
    {
        // margin-top:auto on the footer is what makes a row share one baseline.
        $html = $this->actingAs($this->artistWithWork())->get('/my-tasks')->getContent();

        $this->assertStringContainsString('mt-foot', $html);
        $this->assertMatchesRegularExpression('/\.mt-foot\s*\{[^}]*margin-top:\s*auto/', $html);
    }

    public function test_the_grid_collapses_to_one_column_on_a_phone(): void
    {
        // Two 330px cards side by side on a phone is how the alignment looked
        // worst — they were being squeezed rather than stacked.
        $html = $this->actingAs($this->artistWithWork())->get('/my-tasks')->getContent();

        $this->assertMatchesRegularExpression(
            '/@media\s*\(max-width:\s*560px\)\s*\{\s*\.mt-grid\s*\{\s*grid-template-columns:\s*1fr/',
            $html
        );
    }

    public function test_the_old_hand_rolled_grids_are_gone(): void
    {
        // Three sections each carried their own copy of the grid, at three
        // different minimum widths, so the columns did not agree between them.
        $html = $this->actingAs($this->artistWithWork())->get('/my-tasks')->getContent();

        $this->assertStringNotContainsString('minmax(320px, 1fr)', $html);
        $this->assertSame(
            1,
            preg_match_all('/minmax\(330px, 1fr\)/', $html),
            'the grid should be defined once, in the stylesheet'
        );
    }

    public function test_a_card_says_the_order_and_the_current_step(): void
    {
        $artist = $this->artistWithWork();
        $order = \App\Models\Task::where('assigned_to', $artist->id)
            ->where('status', 'ready')->first()->order;

        $this->actingAs($artist)->get('/my-tasks')
            ->assertOk()
            ->assertSee($order->order_number)
            ->assertSee('Current task')
            ->assertSee('Layout');
    }

    public function test_the_cards_carry_no_pictures_or_file_paths(): void
    {
        // A 180px preview and three wrapped export paths per card is what made
        // the list impossible to line up. Both live on the step itself.
        $html = $this->actingAs($this->artistWithWork())->get('/my-tasks')->getContent();

        $this->assertStringNotContainsString('design-preview', $html);
        $this->assertStringNotContainsString('Export files', $html);
    }

    public function test_the_list_can_be_searched(): void
    {
        $artist = $this->artistWithWork();
        $orders = \App\Models\Task::where('assigned_to', $artist->id)->get()
            ->map(fn ($t) => $t->order->order_number)->values();

        $this->actingAs($artist)->get('/my-tasks?q='.$orders[0])
            ->assertOk()
            ->assertSee($orders[0])
            ->assertDontSee($orders[1]);
    }

    public function test_searching_by_client_finds_the_order(): void
    {
        $artist = $this->artistWithWork();

        $this->actingAs($artist)->get('/my-tasks?q=Grid')
            ->assertOk()
            ->assertSee('Grid Co');
    }
}
