<?php

namespace Tests\Feature;

use App\Models\ProductionOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Every artist-owned specification on the Tech Pack, filled in, saved, and
 * read back.
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
     * JobOrder field => the value typed into it. Distinct per field, so a
     * value landing in the wrong column is a failure rather than coincidence.
     *
     * Client contact, extra-seam and special-instruction boxes were removed
     * from the Tech Pack by design, so they do not belong in this form test.
     */
    private const SHEET_FIELDS = [
        // Production
        'fabric' => 'Dri-fit micro mesh',
        'free_logo_sticker' => 'IC round sticker',

        // Sewing — the four headline seams. Their sizes and threads are
        // measured and used at the machine, so they belong to the sewer.
        'neck' => 'Printed ribbings',
        'cuff_arm_sleeves' => 'Tupi finish',
        'neck_label' => 'IC flat bed',
        'bottom_hem' => 'Straight hem',

        // The sewer/thread fields are NOT here: they are recorded at the
        // sewing station by the person who did the work, and are covered by
        // StationFillsTheSheetTest.

        // Packing instruction chosen up front.
        'packaging' => 'One piece per plastic',
    ];

    private function order(): ProductionOrder
    {
        $sales = User::factory()->create(['job_role' => User::ROLE_SALES, 'is_active' => true]);

        $this->actingAs($sales)->post('/orders', [
            'order_number' => 'IC2026-07777',
            'client_name' => 'Mamangun',
            'client_last_name' => 'Reyes',
            'client_contact' => '0917-555-0000',
            'client_address' => 'Angeles City',
            'due_date' => now()->addWeeks(3)->toDateString(),
            'product_type' => 'round_neck',
            'sizes' => ['M' => 10, 'L' => 5],
        ]);

        $order = ProductionOrder::where('order_number', 'IC2026-07777')->firstOrFail();
        $artist = User::factory()->create(['job_role' => User::JOB_ARTIST, 'is_active' => true]);
        $order->jobOrder()->updateOrCreate(
            ['production_order_id' => $order->id],
            ['status' => 'sent_to_artist', 'created_by' => $sales->id],
        );

        // The officer may fill the Tech Pack only after the client approves
        // the final mockup. Reproduce that real workflow instead of bypassing
        // the gate the application is meant to enforce.
        $order->tasks()->where('department', 'Final mockup')->update([
            'status' => 'complete',
            'approved_at' => now(),
            'assigned_to' => $artist->id,
        ]);
        $order->tasks()->where('department', 'Tech pack')->update([
            'status' => 'in_progress',
            'assigned_to' => $artist->id,
            'approver_role' => 'sales',
        ]);

        return $order->fresh();
    }

    /** Fill in every box and save. */
    private function fillIn(ProductionOrder $order): \Illuminate\Testing\TestResponse
    {
        $task = $order->tasks()->where('department', 'Tech pack')->firstOrFail();

        return $this->actingAs($task->assignee)
            ->post(route('tasks.tech-pack', $task), self::SHEET_FIELDS + [
                'print_type' => 'full_sublimation',
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

        $task = $order->tasks()->where('department', 'Tech pack')->firstOrFail();
        $form = $this->actingAs($task->assignee)
            ->get(route('tasks.job-order', $task))
            ->assertOk();

        foreach (array_keys(self::SHEET_FIELDS) as $field) {
            $form->assertSee('name="'.$field.'"', false);
        }
    }

    public function test_what_was_typed_comes_back_into_the_form_to_be_edited(): void
    {
        $order = $this->order();
        $this->fillIn($order);

        $task = $order->tasks()->where('department', 'Tech pack')->firstOrFail();
        $form = $this->actingAs($task->assignee)
            ->get(route('tasks.job-order', $task))
            ->assertOk();

        // A field that saves but doesn't reload is just as broken: the next
        // person to open the sheet blanks it by saving again.
        foreach (self::SHEET_FIELDS as $field => $typed) {
            $form->assertSee(e($typed), false);
        }
    }
}
