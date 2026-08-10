<?php

namespace Tests\Feature;

use App\Models\ProductionOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Every box on the job order sheet, filled in, saved, and read back.
 *
 * The sewing block alone is twenty-odd fields wired through a migration, the
 * model's $fillable, the controller's validation, the form and the printed
 * sheet. Miss any one of those five and the field silently does nothing — the
 * user types into it, saves, and the value is gone with no error. This walks
 * the whole path for every field at once so that can't happen unnoticed.
 */
class JobOrderSheetSavesEveryFieldTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Field => the value typed into it. Distinct per field, so a value landing
     * in the wrong column is a failure rather than a coincidence.
     */
    private const SHEET_FIELDS = [
        'fb_viber_gc' => 'Mamangun Sports GC',

        // Production
        'fabric' => 'Dri-fit micro mesh',
        'free_logo_sticker' => 'IC round sticker',

        // Sewing — the four headline seams and their size/thread
        'neck' => 'Printed ribbings',
        'neck_size' => '2.5 inches',
        'cuff_arm_sleeves' => 'Tupi finish',
        'cuff_size' => '3 inches',
        'neck_label' => 'IC flat bed',
        'bottom_hem' => 'Straight hem',

        // The sewer/thread fields are NOT here: they are recorded at the
        // sewing station by the person who did the work, and are covered by
        // StationFillsTheSheetTest.

        // …and the spare column's name, which IS a decision made up front.
        'extra_seam_label' => 'Shoulder taping',
        // Quality check + agent notes
        'packaging' => 'One piece per plastic',
        'special_instructions' => 'Fold sleeves inward before packing.',
    ];

    private function order(): ProductionOrder
    {
        $sales = User::factory()->create(['job_role' => User::ROLE_SALES, 'is_active' => true]);

        $this->actingAs($sales)->post('/orders', [
            'order_number' => 'IC2026-07777',
            'client_name' => 'Mamangun',
            'client_last_name' => 'Reyes',
            'client_contact' => '0917-555-0000',
            'client_office_address' => 'Angeles City',
            'client_delivery_address' => 'Angeles City',
            'due_date' => now()->addWeeks(3)->toDateString(),
            'product_type' => 'round_neck',
            'sizes' => ['M' => 10, 'L' => 5],
        ]);

        $order = ProductionOrder::where('order_number', 'IC2026-07777')->firstOrFail();
        $order->jobOrder()->create(['status' => 'draft', 'created_by' => $sales->id]);

        return $order->fresh();
    }

    /** Fill in every box and save. */
    private function fillIn(ProductionOrder $order): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs(User::find($order->created_by))
            ->post("/job-orders/{$order->id}/update", self::SHEET_FIELDS + [
                'print_type' => 'Full Sublimation',
                'printer' => 'atexco',
            ]);
    }

    public function test_every_box_on_the_sheet_survives_a_save(): void
    {
        $order = $this->order();

        $this->fillIn($order)->assertSessionHasNoErrors()->assertRedirect();

        $jo = $order->fresh()->jobOrder;

        foreach (self::SHEET_FIELDS as $field => $typed) {
            $this->assertSame($typed, $jo->$field,
                "'$field' did not come back from the database — check the migration, \$fillable and the controller's validation all know about it");
        }
    }

    public function test_every_saved_value_is_printed_on_the_sheet(): void
    {
        $order = $this->order();
        $this->fillIn($order);

        $html = $this->actingAs(User::find($order->created_by))
            ->get("/orders/{$order->id}/job-order")
            ->assertOk()
            ->getContent();

        // Some boxes are shouted on the sheet and some are printed as typed,
        // which is a presentation choice — what matters here is that the value
        // reached the page at all.
        foreach (self::SHEET_FIELDS as $field => $typed) {
            $this->assertStringContainsStringIgnoringCase(e($typed), $html,
                "'$field' was saved but never printed on the sheet");
        }
    }

    public function test_the_form_offers_a_box_for_every_field(): void
    {
        $order = $this->order();

        $form = $this->actingAs(User::find($order->created_by))
            ->get("/job-orders/{$order->id}/edit")
            ->assertOk();

        foreach (array_keys(self::SHEET_FIELDS) as $field) {
            $form->assertSee('name="'.$field.'"', false);
        }
    }

    public function test_what_was_typed_comes_back_into_the_form_to_be_edited(): void
    {
        $order = $this->order();
        $this->fillIn($order);

        $form = $this->actingAs(User::find($order->created_by))
            ->get("/job-orders/{$order->id}/edit")
            ->assertOk();

        // A field that saves but doesn't reload is just as broken: the next
        // person to open the sheet blanks it by saving again.
        foreach (self::SHEET_FIELDS as $field => $typed) {
            $form->assertSee(e($typed), false);
        }
    }
}
