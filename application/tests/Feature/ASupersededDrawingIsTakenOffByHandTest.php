<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Inquiry;
use App\Models\InquiryDesign;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Taking a superseded drawing off a design — by hand, because nothing else works.
 *
 * Submitting a redraw appends, so the version the client rejected stays in the
 * list and is shown beside the one that was agreed: on the brief page, and on
 * the job order as a reference the floor actually works from.
 *
 * Which one is superseded cannot be worked out from what is stored. Two
 * attempts at inferring it from the file count both hid real work — a panel of
 * a three-piece windbreaker set, and the hoodie from a design that is a
 * windbreaker AND a hoodie. A design of two files with one revision looks
 * identical either way; only the names tell them apart, and names are not a
 * rule. So a person decides, and this is how.
 *
 * Marked, never deleted: the file stays on disk and keeps its position in the
 * list, because that position is the address every link to it uses.
 */
class ASupersededDrawingIsTakenOffByHandTest extends TestCase
{
    use RefreshDatabase;

    private function officer(): User
    {
        return User::factory()->create(['job_role' => User::ROLE_SALES, 'is_active' => true]);
    }

    /** A design carrying $count drawings, as a redraw leaves them. */
    private function design(User $officer, User $artist, int $count = 2): InquiryDesign
    {
        $inquiry = Inquiry::create([
            'client_id' => Client::create(['name' => 'Draw', 'last_name' => 'Client'])->id,
            'created_by' => $officer->id,
            'team' => $officer->team,
            'status' => Inquiry::STATUS_OPEN,
            'what_they_want' => 'Shirts',
        ]);

        $files = [];
        for ($i = 1; $i <= $count; $i++) {
            $files[] = [
                'path' => 'inquiry-layouts/drawing-'.$i.'.jpg',
                'original_name' => 'Drawing '.$i.'.jpg',
                'mime' => 'image/jpeg',
                'size' => 1000,
                'uploaded_by' => $artist->id,
                'kind' => 'layout',
            ];
        }

        return $inquiry->designs()->create([
            'position' => 1,
            'artist_id' => $artist->id,
            'status' => InquiryDesign::STATUS_SUBMITTED,
            'revision_count' => 1,
            'files' => $files,
        ]);
    }

    private function remove(User $as, InquiryDesign $design, int $index)
    {
        return $this->actingAs($as)->post(
            route('inquiries.designs.drawing.remove', [$design->inquiry_id, $design->id]),
            ['index' => $index]
        );
    }

    public function test_the_officer_can_take_a_replaced_drawing_off(): void
    {
        $officer = $this->officer();
        $design = $this->design($officer, User::factory()->create(['job_role' => User::JOB_ARTIST]));

        $this->assertSame(2, $design->drawings()->count());

        $this->remove($officer, $design, 0)->assertRedirect()->assertSessionHasNoErrors();

        $design->refresh();
        $this->assertSame(1, $design->drawings()->count());
        $this->assertSame('Drawing 2.jpg', $design->drawings()->first()['original_name']);
    }

    /** Marked, not destroyed — and the ones left keep their addresses. */
    public function test_the_file_is_kept_and_the_others_do_not_move(): void
    {
        $officer = $this->officer();
        $design = $this->design($officer, User::factory()->create(['job_role' => User::JOB_ARTIST]), 3);

        $this->remove($officer, $design, 0)->assertRedirect();

        $design->refresh();

        // Still three entries: nothing was deleted.
        $this->assertCount(3, $design->files);
        $this->assertSame('superseded', $design->files[0]['kind']);
        $this->assertSame('inquiry-layouts/drawing-1.jpg', $design->files[0]['path']);

        // And the survivors are still at 1 and 2, which is what their links say.
        $this->assertSame([1, 2], $design->drawings()->keys()->all());
    }

    /** A design with nothing on it cannot be approved, so the last one stays. */
    public function test_the_only_drawing_cannot_be_taken_off(): void
    {
        $officer = $this->officer();
        $design = $this->design($officer, User::factory()->create(['job_role' => User::JOB_ARTIST]), 1);

        $this->remove($officer, $design, 0)
            ->assertRedirect()
            ->assertSessionHasErrors('drawings');

        $this->assertSame(1, $design->refresh()->drawings()->count());
    }

    public function test_the_artist_who_drew_it_can_take_one_off(): void
    {
        $artist = User::factory()->create(['job_role' => User::JOB_ARTIST, 'is_active' => true]);
        $design = $this->design($this->officer(), $artist);

        $this->remove($artist, $design, 0)->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(1, $design->refresh()->drawings()->count());
    }

    /**
     * The artist leader moves work between artists; he does not decide what
     * the client agreed to.
     */
    public function test_somebody_elses_design_is_not_theirs_to_change(): void
    {
        $design = $this->design($this->officer(), User::factory()->create(['job_role' => User::JOB_ARTIST]));

        foreach ([User::JOB_ARTIST_LEAD, User::ROLE_SALES, User::JOB_ARTIST] as $role) {
            $outsider = User::factory()->create(['job_role' => $role, 'is_active' => true]);
            $this->remove($outsider, $design, $role === User::ROLE_SALES ? 0 : 0)->assertForbidden();
        }

        $this->assertSame(2, $design->refresh()->drawings()->count());
    }

    /** The floor works from these, so the old one has to stop being a reference. */
    public function test_it_is_pulled_off_the_job_orders_references_too(): void
    {
        $officer = $this->officer();
        $artist = User::factory()->create(['job_role' => User::JOB_ARTIST]);
        $design = $this->design($officer, $artist);

        $order = \App\Models\ProductionOrder::create([
            'order_number' => 'IC2026-DRAW1',
            'client_id' => $design->inquiry->client_id,
            'customer_name' => 'Draw Client',
            'product_type' => 'round_neck',
            'quantity' => 10,
            'due_date' => now()->addWeeks(2),
            'status' => 'active',
            'created_by' => $officer->id,
            'inquiry_id' => $design->inquiry_id,
        ]);
        $design->inquiry->update(['production_order_id' => $order->id]);

        $jobOrder = $order->jobOrder()->create(['status' => 'draft', 'created_by' => $officer->id]);
        foreach ($design->files as $file) {
            $jobOrder->referenceFiles()->create([
                'path' => $file['path'],
                'original_name' => $file['original_name'],
                'kind' => 'layout',
            ]);
        }

        $this->assertSame(2, $jobOrder->referenceFiles()->count());

        $this->remove($officer, $design, 0)->assertRedirect();

        $this->assertSame(1, $jobOrder->refresh()->referenceFiles()->count(),
            'the floor was left working from the drawing that was replaced');
    }

    public function test_the_button_is_offered_to_the_officer_and_not_to_a_reader(): void
    {
        $officer = $this->officer();
        $design = $this->design($officer, User::factory()->create(['job_role' => User::JOB_ARTIST]));

        // The form's own address, not its class: layout-file-remove is the
        // page's existing style for taking a brief file off and sits in the
        // stylesheet whether or not anybody is offered the button.
        $where = route('inquiries.designs.drawing.remove', [$design->inquiry_id, $design->id]);

        $this->actingAs($officer)->get(route('inquiries.layout', $design->inquiry))
            ->assertOk()
            ->assertSee($where, false);

        // The artist leader reads this page; the remove is not his.
        $lead = User::factory()->create(['job_role' => User::JOB_ARTIST_LEAD, 'is_active' => true]);
        $this->actingAs($lead)->get(route('inquiries.layout', $design->inquiry))
            ->assertOk()
            ->assertDontSee($where, false);
    }
}
