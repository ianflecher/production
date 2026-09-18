<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Inquiry;
use App\Models\InquiryDesign;
use App\Models\OrderDocument;
use App\Models\ProductionOrder;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Every garment on the quotation gets its own printed mockup page.
 *
 * One brief is quoted on one sheet now, so a single design page behind it gave
 * the client a page headed "Mockup" showing the shirt, and nothing at all for
 * the polo and the hoodie they are also paying for.
 *
 * One sheet per garment that has a drawing, in the order they are priced.
 */
class EveryGarmentGetsItsMockupPageTest extends TestCase
{
    use RefreshDatabase;

    private function officer(): User
    {
        return User::factory()->create(['job_role' => User::ROLE_SALES, 'is_active' => true]);
    }

    private function brief(User $officer): Inquiry
    {
        $client = Client::create([
            'name' => 'Boys Of', 'last_name' => 'South',
            'contact_number' => '0917-000-0000', 'created_by' => $officer->id,
        ]);

        return Inquiry::create([
            'client_id' => $client->id,
            'what_they_want' => 'Shirts, polos and hoodies',
            'created_by' => $officer->id,
            'status' => Inquiry::STATUS_OPEN,
        ]);
    }

    /** One garment, with or without a drawing handed in for it. */
    private function garment(Inquiry $inquiry, User $officer, string $label, int $position, bool $drawn = true, array $sizes = ['M']): ProductionOrder
    {
        $design = InquiryDesign::create([
            'inquiry_id' => $inquiry->id,
            'position' => $position,
            'label' => $label,
            'status' => InquiryDesign::STATUS_APPROVED,
        ]);

        $order = ProductionOrder::create([
            'order_number' => 'IC'.now()->format('Y').'-06060',
            'customer_name' => 'Boys Of South',
            'client_id' => $inquiry->client_id,
            'inquiry_id' => $inquiry->id,
            'inquiry_design_id' => $design->id,
            'product_type' => 'round_neck',
            'quantity' => 10,
            'unit_price' => 550,
            'due_date' => now()->addWeeks(3),
            'created_by' => $officer->id,
            'status' => 'active',
        ]);

        foreach ($sizes as $size) {
            $order->items()->create(['size' => $size, 'quantity' => 10, 'description' => $label]);
        }

        if ($drawn) {
            $task = $order->tasks()->where('department', 'Final mockup')->first()
                ?? $order->tasks()->create([
                    'department' => 'Final mockup', 'team' => User::JOB_ARTIST,
                    'sequence' => 2, 'status' => 'complete',
                ]);

            $task->files()->create([
                'path' => UploadedFile::fake()->image($label.'.jpg')->store('task-files', 'local'),
                'original_name' => $label.'.jpg',
                'mime' => 'image/jpeg',
                'size' => 900,
                'round' => ($task->revision_count ?? 0) + 1,
                'uploaded_by' => $officer->id,
            ]);
        }

        return $order->fresh();
    }

    /** @return array{0: User, 1: ProductionOrder} officer, and the anchor order */
    private function threeDrawnGarments(): array
    {
        Storage::fake('local');
        $officer = $this->officer();
        $inquiry = $this->brief($officer);

        $first = $this->garment($inquiry, $officer, 'BOS COTTON SHIRT', 0);
        $this->garment($inquiry, $officer, 'BOS POLO SHIRT', 1);
        $this->garment($inquiry, $officer, 'BOS COTTON HOODIE', 2);

        return [$officer, $first];
    }

    /**
     * A brief the size of a real one: four garments, six sizes each, which is
     * what Stephanie Moto's sheet actually came to (22 lines, 4 headings).
     */
    private function longBrief(): array
    {
        Storage::fake('local');
        $officer = $this->officer();
        $inquiry = $this->brief($officer);
        $sizes = ['S', 'M', 'L', 'XL', '2XL', '3XL'];

        $first = $this->garment($inquiry, $officer, 'BOS COTTON SHIRT', 0, sizes: $sizes);
        $this->garment($inquiry, $officer, 'BOS POLO SHIRT', 1, sizes: $sizes);
        $this->garment($inquiry, $officer, 'BOS COTTON HOODIE', 2, sizes: $sizes);
        $this->garment($inquiry, $officer, 'BOS WINDBREAKER', 3, sizes: $sizes);

        return [$officer, $first];
    }

    private function sheet(User $officer, ProductionOrder $order): string
    {
        return $this->actingAs($officer)
            ->get(route('orders.document', [$order, OrderDocument::TYPE_DR]))
            ->assertOk()->getContent();
    }

    /* ---------------- a page each ---------------- */

    public function test_each_garment_gets_its_own_printed_page(): void
    {
        [$officer, $anchor] = $this->threeDrawnGarments();

        $html = $this->sheet($officer, $anchor);

        $this->assertSame(3, substr_count($html, 'data-rotate-store='),
            'the client got one mockup page for a three-garment quotation');
    }

    /** Each page says which garment it is, so three sheets are not three "Mockup"s. */
    public function test_each_page_names_its_garment(): void
    {
        [$officer, $anchor] = $this->threeDrawnGarments();

        $html = $this->sheet($officer, $anchor);

        foreach (['BOS COTTON SHIRT', 'BOS POLO SHIRT', 'BOS COTTON HOODIE'] as $label) {
            $this->assertStringContainsString($label, $html);
        }

        // Numbered, so a printed stack can be put back in order.
        $this->assertStringContainsString('1 of 3', $html);
        $this->assertStringContainsString('3 of 3', $html);
    }

    /** A garment nobody has drawn yet is not given a blank sheet. */
    public function test_a_garment_with_no_drawing_gets_no_page(): void
    {
        Storage::fake('local');
        $officer = $this->officer();
        $inquiry = $this->brief($officer);

        $anchor = $this->garment($inquiry, $officer, 'BOS COTTON SHIRT', 0);
        $this->garment($inquiry, $officer, 'BOS POLO SHIRT', 1, drawn: false);

        $html = $this->sheet($officer, $anchor);

        $this->assertSame(1, substr_count($html, 'data-rotate-store='));
    }

    /* ---------------- turning them ---------------- */

    /** Each page carries its own orientation, so turning one leaves the rest. */
    public function test_each_page_stores_its_own_orientation(): void
    {
        [$officer, $anchor] = $this->threeDrawnGarments();

        $html = $this->sheet($officer, $anchor);

        // The first keeps the original key, so an orientation saved before the
        // sheet covered the whole brief still applies to it.
        $this->assertStringContainsString('name="fields[design_rotated]"', $html);
        $this->assertSame(3, substr_count($html, 'name="fields[design_rotated'));
    }

    /* ---------------- and not in the header ---------------- */

    /**
     * The header corner has room for one drawing. On a shared sheet it showed
     * the first garment's, at the top of a quotation that also prices the
     * others, which reads as if the whole sheet were for that one.
     */
    public function test_a_shared_sheet_has_no_drawing_in_the_header(): void
    {
        [$officer, $anchor] = $this->threeDrawnGarments();

        $html = $this->sheet($officer, $anchor);

        // The label under the header thumbnail, which only that corner prints.
        $this->assertStringNotContainsString(
            'letter-spacing:0.05em; margin-top:0.1rem;">Mockup</div>', $html);

        // The flatlay corner beside it is untouched.
        $this->assertStringContainsString('Upload Flatlay', $html);
    }

    /** A single-garment sheet keeps the drawing at the top, as it always had. */
    public function test_a_lone_sheet_keeps_its_header_drawing(): void
    {
        Storage::fake('local');
        $officer = $this->officer();
        $inquiry = $this->brief($officer);
        $only = $this->garment($inquiry, $officer, 'ONE SHIRT', 0);

        $this->assertStringContainsString(
            'letter-spacing:0.05em; margin-top:0.1rem;">Mockup</div>',
            $this->sheet($officer, $only));
    }

    /* ---------------- it prints on one sheet ---------------- */

    /**
     * Stephanie Moto's four garments came to 22 lines and 4 headings, which
     * pushed the terms, totals and signatures onto a second page. A signature
     * block on a page of its own is no use to anybody, so the sheet shrinks to
     * fit rather than spilling.
     */
    public function test_a_long_sheet_shrinks_to_one_page(): void
    {
        [$officer, $anchor] = $this->longBrief();

        $html = $this->sheet($officer, $anchor);

        $this->assertMatchesRegularExpression('/--print-zoom:(0\.\d+)/', $html,
            'a four-garment sheet printed at full size and spilled onto a second page');

        preg_match('/--print-zoom:([\d.]+)/', $html, $m);
        $this->assertLessThan(1.0, (float) $m[1]);
        $this->assertGreaterThanOrEqual(0.55, (float) $m[1], 'shrunk past readable');
    }

    /** A short sheet is left alone at full size. */
    public function test_a_short_sheet_is_not_shrunk(): void
    {
        Storage::fake('local');
        $officer = $this->officer();
        $inquiry = $this->brief($officer);
        $only = $this->garment($inquiry, $officer, 'ONE SHIRT', 0);

        preg_match('/--print-zoom:([\d.]+)/', $this->sheet($officer, $only), $m);

        $this->assertSame(1.0, (float) $m[1]);
    }

    /** The mockup pages are whole pages of their own and must not shrink. */
    public function test_only_the_quotation_is_shrunk(): void
    {
        [$officer, $anchor] = $this->threeDrawnGarments();

        $html = $this->sheet($officer, $anchor);

        // The zoom is carried by the sheet itself, not by the design pages.
        $this->assertSame(1, substr_count($html, '--print-zoom:'));
        $this->assertStringContainsString('zoom: var(--print-zoom, 1)', $html);
    }

    /* ---------------- a lone order is unchanged ---------------- */

    public function test_a_single_garment_still_gets_one_page(): void
    {
        Storage::fake('local');
        $officer = $this->officer();
        $inquiry = $this->brief($officer);
        $only = $this->garment($inquiry, $officer, 'ONE SHIRT', 0);

        $html = $this->sheet($officer, $only);

        $this->assertSame(1, substr_count($html, 'data-rotate-store='));
        // No "1 of 1" on a sheet with nothing to count.
        $this->assertStringNotContainsString('1 of 1', $html);
    }
}
