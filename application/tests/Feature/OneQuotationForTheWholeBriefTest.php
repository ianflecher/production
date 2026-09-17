<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Inquiry;
use App\Models\InquiryDesign;
use App\Models\OrderDocument;
use App\Models\ProductionOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * One brief, one price quotation.
 *
 * A client who asks for cotton shirts, polos and hoodies is quoted once, on
 * one sheet, with each garment under its own drawing — the way the office has
 * always typed it into Excel.
 *
 * The sheet was built from a single production order, so that client got three
 * separate quotations that never mentioned each other. Stephanie Moto's brief
 * would have made four and Gian Lasam's eight, and no sheet in the system ever
 * showed the job the client actually asked for.
 */
class OneQuotationForTheWholeBriefTest extends TestCase
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

    /** One garment on the brief: its design, its order, its sizes. */
    private function garment(Inquiry $inquiry, User $officer, string $label, array $sizes, float $price, int $position): ProductionOrder
    {
        $design = InquiryDesign::create([
            'inquiry_id' => $inquiry->id,
            'position' => $position,
            'label' => $label,
            'status' => InquiryDesign::STATUS_APPROVED,
        ]);

        $order = ProductionOrder::create([
            'order_number' => 'IC'.now()->format('Y').'-05050',
            'customer_name' => 'Boys Of South',
            'client_id' => $inquiry->client_id,
            'inquiry_id' => $inquiry->id,
            'inquiry_design_id' => $design->id,
            'product_type' => 'round_neck',
            'quantity' => array_sum($sizes),
            'unit_price' => $price,
            'due_date' => now()->addWeeks(3),
            'created_by' => $officer->id,
            'status' => 'active',
        ]);

        foreach ($sizes as $size => $qty) {
            $order->items()->create(['size' => $size, 'quantity' => $qty, 'description' => $label]);
        }

        return $order->fresh();
    }

    /** @return array{0: User, 1: Inquiry, 2: ProductionOrder, 3: ProductionOrder, 4: ProductionOrder} */
    private function threeGarments(): array
    {
        $officer = $this->officer();
        $inquiry = $this->brief($officer);

        $shirt = $this->garment($inquiry, $officer, 'BOS COTTON SHIRT', ['M' => 4, 'L' => 6], 550, 0);
        $polo = $this->garment($inquiry, $officer, 'BOS POLO SHIRT', ['M' => 3, 'L' => 14], 650, 1);
        $hoodie = $this->garment($inquiry, $officer, 'BOS COTTON HOODIE', ['M' => 5, 'L' => 11], 1000, 2);

        return [$officer, $inquiry, $shirt, $polo, $hoodie];
    }

    /* ---------------- what the sheet covers ---------------- */

    public function test_the_sheet_prices_every_garment_on_the_brief(): void
    {
        [, , $shirt, $polo, $hoodie] = $this->threeGarments();

        $covered = $shirt->quotationOrders()->pluck('id')->all();

        $this->assertSame([$shirt->id, $polo->id, $hoodie->id], $covered);
    }

    /** An order written on its own is still just itself. */
    public function test_a_lone_order_is_quoted_alone(): void
    {
        $officer = $this->officer();
        $inquiry = $this->brief($officer);
        $only = $this->garment($inquiry, $officer, 'ONE SHIRT', ['M' => 2], 550, 0);

        $this->assertSame([$only->id], $only->quotationOrders()->pluck('id')->all());
    }

    /* ---------------- the lines ---------------- */

    public function test_every_garments_sizes_are_on_one_sheet(): void
    {
        [$officer, , $shirt] = $this->threeGarments();

        $this->actingAs($officer)->get(route('orders.document', [$shirt, OrderDocument::TYPE_DR]))->assertOk();

        $items = collect($shirt->fresh()->documents()->firstWhere('type', OrderDocument::TYPE_DR)->items);

        foreach (['BOS COTTON SHIRT', 'BOS POLO SHIRT', 'BOS COTTON HOODIE'] as $label) {
            $this->assertTrue($items->contains('group', $label), $label.' is missing from the quotation');
        }
    }

    /** The money adds up across all three, not just the one it was opened from. */
    public function test_the_total_is_the_whole_brief(): void
    {
        [$officer, , $shirt] = $this->threeGarments();

        $this->actingAs($officer)->get(route('orders.document', [$shirt, OrderDocument::TYPE_DR]))->assertOk();

        $doc = $shirt->fresh()->documents()->firstWhere('type', OrderDocument::TYPE_DR);

        // 10 shirts @550 + 17 polos @650 + 16 hoodies @1000
        $garments = (10 * 550) + (17 * 650) + (16 * 1000);

        $this->assertGreaterThanOrEqual($garments, $doc->totals()['amount'],
            'the sheet priced only part of the brief');
        $this->assertSame(43, $doc->totals()['quantity']);
    }

    /* ---------------- one sheet, not three ---------------- */

    /**
     * Opening the quotation from the polo or the hoodie lands on the shirt's
     * copy. Otherwise each sibling grows its own: the officer corrects a price
     * on one, and whoever opens the job from another design is reading a
     * quotation that still has the old one.
     */
    public function test_a_sibling_is_sent_to_the_one_sheet(): void
    {
        [$officer, , $shirt, $polo, $hoodie] = $this->threeGarments();

        foreach ([$polo, $hoodie] as $sibling) {
            $this->actingAs($officer)
                ->get(route('orders.document', [$sibling, OrderDocument::TYPE_DR]))
                ->assertRedirect(route('orders.document', [$shirt, OrderDocument::TYPE_DR]));
        }
    }

    public function test_only_one_document_row_is_ever_made(): void
    {
        [$officer, , $shirt, $polo, $hoodie] = $this->threeGarments();

        foreach ([$shirt, $polo, $hoodie] as $any) {
            $this->actingAs($officer)->get(route('orders.document', [$any, OrderDocument::TYPE_DR]));
        }

        $this->assertSame(1, OrderDocument::where('type', OrderDocument::TYPE_DR)->count());
    }

    /* ---------------- the drawing beside each garment ---------------- */

    public function test_each_garment_is_headed_by_its_own_name(): void
    {
        [$officer, , $shirt] = $this->threeGarments();

        $html = $this->actingAs($officer)
            ->get(route('orders.document', [$shirt, OrderDocument::TYPE_DR]))
            ->assertOk()->getContent();

        // One heading row per garment, not one per size line.
        $this->assertSame(3, substr_count($html, 'class="group-row"'));

        foreach (['BOS COTTON SHIRT', 'BOS POLO SHIRT', 'BOS COTTON HOODIE'] as $label) {
            $this->assertStringContainsString($label, $html);
        }
    }

    /** A sheet for one garment needs no headings at all. */
    public function test_a_lone_order_gets_no_grouping(): void
    {
        $officer = $this->officer();
        $inquiry = $this->brief($officer);
        $only = $this->garment($inquiry, $officer, 'ONE SHIRT', ['M' => 2], 550, 0);

        $html = $this->actingAs($officer)
            ->get(route('orders.document', [$only, OrderDocument::TYPE_DR]))
            ->assertOk()->getContent();

        $this->assertStringNotContainsString('class="group-row"', $html);
    }

    /* ---------------- editing it ---------------- */

    /** A corrected price stays under the garment it belongs to. */
    public function test_an_edited_line_keeps_its_garment(): void
    {
        [$officer, , $shirt] = $this->threeGarments();

        $this->actingAs($officer)->get(route('orders.document', [$shirt, OrderDocument::TYPE_DR]));
        $doc = $shirt->fresh()->documents()->firstWhere('type', OrderDocument::TYPE_DR);

        $items = collect($doc->items)->map(function ($row) {
            if (($row['group'] ?? null) === 'BOS POLO SHIRT') {
                $row['unit_price'] = 700;
            }

            return $row;
        })->all();

        $this->actingAs($officer)
            ->post(route('orders.document.save', [$shirt, OrderDocument::TYPE_DR]), [
                'number' => $doc->number,
                'items' => $items,
            ])->assertSessionHasNoErrors();

        $saved = collect($doc->fresh()->items)->where('group', 'BOS POLO SHIRT');

        $this->assertTrue($saved->isNotEmpty(), 'the polo lines lost their garment on save');
        $this->assertTrue($saved->every(fn ($r) => (float) $r['unit_price'] === 700.0));
    }
}
