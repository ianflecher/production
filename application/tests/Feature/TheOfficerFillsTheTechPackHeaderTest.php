<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ProductionOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The header of the tech pack is the account officer's, not the artist's.
 *
 * Who the job is for, what garment, which print and printer, what fabric — the
 * office knows all of it when the job is taken. The artist was retyping it off
 * the order form and guessing wherever the two disagreed.
 */
class TheOfficerFillsTheTechPackHeaderTest extends TestCase
{
    use RefreshDatabase;

    private function order(User $officer): ProductionOrder
    {
        $order = ProductionOrder::create([
            'order_number' => 'IC2026-0'.random_int(1000, 9999),
            'customer_name' => 'Kaela Ruiz',
            'client_id' => Client::create([
                'name' => 'Kaela', 'last_name' => 'Ruiz', 'contact_number' => '0917',
                'office_address' => 'Angeles City', 'delivery_address' => 'Angeles City',
                'created_by' => $officer->id,
            ])->id,
            'product_type' => 'round_neck',
            'quantity' => 10,
            'unit_price' => 500,
            'total_price' => 5000,
            'due_date' => now()->addWeeks(3)->toDateString(),
            'status' => 'active',
            'created_by' => $officer->id,
        ]);

        $order->jobOrder()->create(['status' => 'draft', 'created_by' => $officer->id]);

        return $order->fresh();
    }

    private function header(): array
    {
        return [
            'design_name' => 'BREAD WINNER SHIRT BLACK',
            'fitting' => 'ORIGINAL FIT',
            'item_style' => 'SHIRT',
            'print_type' => 'DTF',
            'printer' => 'dtf_printer',
            'fabric' => 'Provided by client',
        ];
    }

    public function test_the_officers_page_actually_offers_the_boxes(): void
    {
        // Saving worked while the page still printed the header as read-only
        // text - there was nowhere to type. Assert the FORM, not just the post.
        $officer = User::factory()->create(['job_role' => User::ROLE_SALES, 'is_active' => true]);
        $order = $this->order($officer);

        $this->actingAs($officer)->get(route('job-orders.edit', $order))
            ->assertOk()
            ->assertSee('name="design_name"', false)
            ->assertSee('name="fitting"', false)
            ->assertSee('name="item_style"', false)
            ->assertSee('name="print_type"', false)
            ->assertSee('name="printer"', false)
            ->assertSee('name="fabric"', false);
    }

    public function test_the_account_officer_fills_the_header(): void
    {
        $officer = User::factory()->create(['job_role' => User::ROLE_SALES, 'is_active' => true]);
        $order = $this->order($officer);

        $this->actingAs($officer)
            ->post(route('job-orders.update', $order), $this->header())
            ->assertRedirect(route('job-orders.edit', $order));

        $pack = $order->fresh()->techPack;
        $this->assertSame('BREAD WINNER SHIRT BLACK', $pack->design_name);
        $this->assertSame('ORIGINAL FIT', $pack->fitting);
        $this->assertSame('SHIRT', $pack->item_style);

        $jo = $order->fresh()->jobOrder;
        $this->assertSame('DTF', $jo->print_type);
        $this->assertSame('dtf_printer', $jo->printer);
        $this->assertSame('Provided by client', $jo->fabric);
    }

    public function test_the_header_is_filled_before_the_mockup_is_approved(): void
    {
        // The officer fills this when the job is taken, which is well before
        // any mockup exists. The old gate refused the save outright.
        $officer = User::factory()->create(['job_role' => User::ROLE_SALES, 'is_active' => true]);
        $order = $this->order($officer);

        $this->assertFalse($order->mockupApproved());

        $this->actingAs($officer)
            ->post(route('job-orders.update', $order), $this->header())
            ->assertSessionHasNoErrors();

        $this->assertSame('SHIRT', $order->fresh()->techPack->item_style);
    }

    public function test_an_artist_cannot_fill_the_header_here(): void
    {
        $officer = User::factory()->create(['job_role' => User::ROLE_SALES, 'is_active' => true]);
        $artist = User::factory()->create(['job_role' => 'artist', 'is_active' => true]);
        $order = $this->order($officer);

        $this->actingAs($artist)
            ->post(route('job-orders.update', $order), $this->header())
            ->assertForbidden();

        $this->assertNull($order->fresh()->techPack);
    }

    public function test_the_spec_boxes_are_the_officers_too(): void
    {
        // Every typed box on the sheet is read here now, not only the header.
        // The artist's own half — the pictures and the file location — is
        // still not accepted however this form is posted.
        $officer = User::factory()->create(['job_role' => User::ROLE_SALES, 'is_active' => true]);
        $order = $this->order($officer);

        $this->actingAs($officer)->post(
            route('job-orders.update', $order),
            $this->header() + [
                'tshirt_color' => 'Black',
                'placing_title' => 'Standard DTF placing',
                'zipper_type' => 'Metal',
                'neck_label' => 'IC DTF - original fit',
                'packaging' => 'Polybag',
                'tag_1_details' => 'NOT THEIRS EITHER',
                'file_location_notes' => 'NOT THEIRS',
            ]
        )->assertSessionHasNoErrors();

        $pack = $order->fresh()->techPack;
        $this->assertSame('SHIRT', $pack->item_style);
        $this->assertSame('Black', $pack->tshirt_color);
        $this->assertSame('Standard DTF placing', $pack->placing_title);
        $this->assertSame('Metal', $pack->zipper_type);
        // The tag notes stay the artist's: they are read against the picture
        // they sit beside, not typed from a desk that cannot see the layout.
        $this->assertNull($pack->tag_1_details);
        $this->assertNull($pack->file_location_notes);

        $jo = $order->fresh()->jobOrder;
        $this->assertSame('IC DTF - original fit', $jo->neck_label);
        $this->assertSame('Polybag', $jo->packaging);
    }
}
