<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Inquiry;
use App\Models\InquiryDesign;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * One client, one brief, many designs - split between artists.
 *
 * Stephanie Moto wants six: five are Cristal's and the sixth is Mick's. An
 * enquiry carried ONE layout, so the shop either opened six enquiries for one
 * client, losing the fact that it is one job, or piled six drawings onto one
 * layout where they went to one artist and the client had to take or reject
 * the lot. "Five approved, one to redo" could not be written down at all.
 */
class ManyDesignsOnOneBriefTest extends TestCase
{
    use RefreshDatabase;

    private function artist(string $name): User
    {
        $artist = User::factory()->create([
            'job_role' => User::JOB_ARTIST, 'name' => $name, 'is_active' => true,
        ]);

        // The rotation only hands work to whoever is in today.
        $artist->attendances()->create(['date' => now()->toDateString(), 'status' => 'present']);

        return $artist;
    }

    /** @return array{0: User, 1: User, 2: User, 3: Inquiry} */
    private function brief(): array
    {
        $officer = User::factory()->create(['job_role' => User::ROLE_SALES, 'is_active' => true]);
        $cristal = $this->artist('Cristal');
        $mick = $this->artist('Mick');

        $client = Client::create([
            'name' => 'Stephanie', 'last_name' => 'Moto', 'company' => 'Stephanie Moto',
            'contact_number' => '0917 555 0000', 'created_by' => $officer->id,
        ]);

        $inquiry = Inquiry::create([
            'client_id' => $client->id,
            'created_by' => $officer->id,
            'status' => Inquiry::STATUS_OPEN,
            'what_they_want' => 'Six designs for the team',
        ]);

        return [$officer, $cristal, $mick, $inquiry];
    }

    public function test_the_officer_lists_six_designs_split_between_two_artists(): void
    {
        [$officer, $cristal, $mick, $inquiry] = $this->brief();

        $this->actingAs($officer)->post(route('inquiries.designs.store', $inquiry), [
            'label' => 'Rider', 'how_many' => 5, 'artist_id' => $cristal->id,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->actingAs($officer)->post(route('inquiries.designs.store', $inquiry), [
            'label' => 'Jacket', 'artist_id' => $mick->id,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $designs = $inquiry->fresh()->designs;

        $this->assertCount(6, $designs);
        $this->assertSame(5, $designs->where('artist_id', $cristal->id)->count());
        $this->assertSame(1, $designs->where('artist_id', $mick->id)->count());
        // Numbered as they were added, so the set reads in the order it was
        // listed rather than in whatever order the rows come back.
        $this->assertSame([0, 1, 2, 3, 4, 5], $designs->pluck('position')->all());
        $this->assertSame('Rider 1', $designs->first()->name());
        $this->assertSame('Jacket', $designs->last()->name());
    }

    public function test_each_artist_sees_only_their_own_designs(): void
    {
        [$officer, $cristal, $mick, $inquiry] = $this->brief();

        $this->actingAs($officer)->post(route('inquiries.designs.store', $inquiry),
            ['label' => 'Rider', 'how_many' => 5, 'artist_id' => $cristal->id]);
        $this->actingAs($officer)->post(route('inquiries.designs.store', $inquiry),
            ['label' => 'Jacket', 'artist_id' => $mick->id]);
        $this->actingAs($officer)->post(route('inquiries.layout.complete', $inquiry),
            ['reference_note' => 'Keep the team colours']);

        $this->actingAs($cristal)->get(route('inquiries.layouts'))
            ->assertOk()
            ->assertSee('5 designs for you')
            ->assertSee('Rider 1')
            ->assertDontSee('Jacket');

        $this->actingAs($mick)->get(route('inquiries.layouts'))
            ->assertOk()
            ->assertSee('1 design for you')
            ->assertSee('Jacket')
            ->assertDontSee('Rider 1');
    }

    public function test_each_design_keeps_its_own_description_for_its_artist(): void
    {
        [$officer, $cristal, $mick, $inquiry] = $this->brief();

        $this->actingAs($officer)->post(route('inquiries.designs.store', $inquiry), [
            'label' => 'Jersey',
            'artist_id' => $cristal->id,
            'description' => 'Use the blue team colours and put number 12 on the back.',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->actingAs($officer)->post(route('inquiries.designs.store', $inquiry), [
            'label' => 'Jacket',
            'artist_id' => $mick->id,
            'description' => 'Use the black jacket logo on the left chest only.',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->actingAs($officer)->post(route('inquiries.layout.complete', $inquiry))
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->actingAs($cristal)->get(route('inquiries.layouts'))
            ->assertOk()
            ->assertSee('Use the blue team colours and put number 12 on the back.')
            ->assertDontSee('Use the black jacket logo on the left chest only.');

        $this->actingAs($mick)->get(route('inquiries.layouts'))
            ->assertOk()
            ->assertSee('Use the black jacket logo on the left chest only.')
            ->assertDontSee('Use the blue team colours and put number 12 on the back.');
    }

    public function test_one_design_is_handed_back_while_the_others_are_still_being_drawn(): void
    {
        Storage::fake('local');
        [$officer, $cristal, , $inquiry] = $this->brief();

        $this->actingAs($officer)->post(route('inquiries.designs.store', $inquiry),
            ['label' => 'Rider', 'how_many' => 2, 'artist_id' => $cristal->id]);
        $this->actingAs($officer)->post(route('inquiries.layout.complete', $inquiry), ['reference_note' => 'Keep the team colours']);

        $first = $inquiry->fresh()->designs->first();

        $this->actingAs($cristal)->post(route('inquiries.designs.submit', $first), [
            'files' => [UploadedFile::fake()->image('rider1.png')],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $inquiry->refresh()->load('designs');

        $this->assertTrue($inquiry->designs->first()->submitted());
        $this->assertTrue($inquiry->designs->last()->withArtist());
        // The brief is only as finished as its least finished design.
        $this->assertSame(Inquiry::LAYOUT_WITH_ARTIST, $inquiry->layoutStatus());
    }

    public function test_the_client_approves_five_and_sends_one_back(): void
    {
        Storage::fake('local');
        [$officer, $cristal, , $inquiry] = $this->brief();

        $this->actingAs($officer)->post(route('inquiries.designs.store', $inquiry),
            ['label' => 'Rider', 'how_many' => 6, 'artist_id' => $cristal->id]);
        $this->actingAs($officer)->post(route('inquiries.layout.complete', $inquiry), ['reference_note' => 'Keep the team colours']);

        foreach ($inquiry->fresh()->designs as $design) {
            $this->actingAs($cristal)->post(route('inquiries.designs.submit', $design), [
                'files' => [UploadedFile::fake()->image('d.png')],
            ])->assertRedirect();
        }

        $designs = $inquiry->fresh()->designs;

        foreach ($designs->take(5) as $design) {
            $this->actingAs($officer)->post(route('inquiries.designs.approve', [$inquiry, $design]))
                ->assertRedirect();
        }

        $last = $designs->last();

        $this->actingAs($officer)->post(route('inquiries.designs.revise', [$inquiry, $last]), [
            'revision_note' => 'Make the number bigger.',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $inquiry->refresh()->load('designs');

        $this->assertSame(5, $inquiry->designs->where('status', InquiryDesign::STATUS_APPROVED)->count());
        $this->assertTrue($inquiry->designs->last()->withArtist());
        $this->assertSame(1, (int) $inquiry->designs->last()->revision_count);
        $this->assertSame('Make the number bigger.', $inquiry->designs->last()->revision_note);

        // Five yeses do not open the job order.
        $this->assertNotSame(Inquiry::LAYOUT_APPROVED, $inquiry->layoutStatus());
    }

    public function test_the_job_order_opens_only_when_every_design_is_approved(): void
    {
        Storage::fake('local');
        [$officer, $cristal, , $inquiry] = $this->brief();

        $this->actingAs($officer)->post(route('inquiries.designs.store', $inquiry),
            ['label' => 'Rider', 'how_many' => 2, 'artist_id' => $cristal->id]);
        $this->actingAs($officer)->post(route('inquiries.layout.complete', $inquiry), ['reference_note' => 'Keep the team colours']);

        foreach ($inquiry->fresh()->designs as $design) {
            $this->actingAs($cristal)->post(route('inquiries.designs.submit', $design), [
                'files' => [UploadedFile::fake()->image('d.png')],
            ]);
        }

        $designs = $inquiry->fresh()->designs;

        $this->actingAs($officer)->post(route('inquiries.designs.approve', [$inquiry, $designs->first()]));
        $this->assertNotSame(Inquiry::LAYOUT_APPROVED, $inquiry->fresh()->layoutStatus());

        // The last yes is the one that opens it.
        $this->actingAs($officer)->post(route('inquiries.designs.approve', [$inquiry, $designs->last()]))
            ->assertRedirect(route('orders.create', ['inquiry' => $inquiry->id]));

        $this->assertSame(Inquiry::LAYOUT_APPROVED, $inquiry->fresh()->layoutStatus());
    }

    public function test_a_job_order_can_be_prepared_after_one_of_many_designs_is_approved(): void
    {
        [$officer, $cristal, , $inquiry] = $this->brief();

        $this->actingAs($officer)->post(route('inquiries.designs.store', $inquiry), [
            'label' => 'Rider', 'how_many' => 2, 'artist_id' => $cristal->id,
            'description' => 'Use the approved team artwork.',
        ]);
        $this->actingAs($officer)->post(route('inquiries.layout.complete', $inquiry));

        $first = $inquiry->fresh()->designs->first();
        $first->update(['status' => InquiryDesign::STATUS_SUBMITTED, 'submitted_at' => now()]);

        $this->actingAs($officer)->post(route('inquiries.designs.approve', [$inquiry, $first]))
            ->assertRedirect();

        $this->actingAs($officer)->get(route('orders.create', ['inquiry' => $inquiry->id]))
            ->assertOk()
            ->assertSee('New Job Order');
    }

    public function test_every_approved_design_lands_on_the_job_order(): void
    {
        Storage::fake('local');
        [$officer, $cristal, , $inquiry] = $this->brief();

        $this->actingAs($officer)->post(route('inquiries.designs.store', $inquiry),
            ['label' => 'Rider', 'how_many' => 2, 'artist_id' => $cristal->id]);
        $this->actingAs($officer)->post(route('inquiries.layout.complete', $inquiry), ['reference_note' => 'Keep the team colours']);

        foreach ($inquiry->fresh()->designs as $design) {
            $this->actingAs($cristal)->post(route('inquiries.designs.submit', $design), [
                'files' => [UploadedFile::fake()->image('drawing.png')],
            ]);
            $this->actingAs($officer)->post(route('inquiries.designs.approve', [$inquiry, $design]));
        }

        $this->actingAs($officer)->post(route('orders.store'), [
            'inquiry_id' => $inquiry->id,
            'order_number' => 'IC2026-MOTO1',
            'due_date' => now()->addWeeks(3)->toDateString(),
            'product_type' => 'round_neck',
            'sizes' => ['M' => 6, 'L' => 6],
        ])->assertSessionHasNoErrors();

        $first = \App\Models\ProductionOrder::where('order_number', 'IC2026-MOTO1')->firstOrFail();

        // ONE order per design. The first order is Rider 1's, and it does not
        // arrive carrying Rider 2's drawing - a jacket order with the shorts
        // artwork on it is how the wrong thing gets pressed.
        $names = $first->jobOrder->referenceFiles->pluck('original_name');
        $this->assertTrue($names->contains('Rider 1 - drawing.png'));
        $this->assertFalse($names->contains('Rider 2 - drawing.png'));

        $designs = $inquiry->fresh()->designs;
        $this->assertSame($designs->first()->id, $first->inquiry_design_id);
        $this->assertSame($inquiry->id, $first->inquiry_id);

        // The brief is not finished with: Rider 2 is approved and still has no
        // order, so the client stays on the follow-up list.
        $this->assertSame(1, $inquiry->fresh()->designsAwaitingAnOrder()->count());
        $this->assertTrue(Inquiry::forFollowUp()->whereKey($inquiry->id)->exists());

        // And the second order is written from the same brief.
        $this->actingAs($officer)->post(route('orders.store'), [
            'inquiry_id' => $inquiry->id,
            'inquiry_design_id' => $designs->last()->id,
            'order_number' => 'IC2026-MOTO2',
            'due_date' => now()->addWeeks(3)->toDateString(),
            'product_type' => 'round_neck',
            'sizes' => ['M' => 4],
        ])->assertSessionHasNoErrors();

        // One client, one job order number: the second part of the job takes
        // the number the job already has, whatever was typed for it. The
        // ORDERS stay separate - each design is its own run of work - but the
        // number on both says they are one job.
        $this->assertDatabaseMissing('production_orders', ['order_number' => 'IC2026-MOTO2']);

        $second = \App\Models\ProductionOrder::where('inquiry_design_id', $designs->last()->id)->firstOrFail();

        $this->assertSame('IC2026-MOTO1', $second->order_number);
        $this->assertSame($designs->last()->id, $second->inquiry_design_id);
        $this->assertTrue($second->jobOrder->referenceFiles->pluck('original_name')
            ->contains('Rider 2 - drawing.png'));

        // One brief, two orders under one number, one client to chase.
        $this->assertSame(2, $inquiry->fresh()->orders()->count());
        $this->assertSame(2, \App\Models\ProductionOrder::where('order_number', 'IC2026-MOTO1')->count());
        $this->assertSame(0, $inquiry->fresh()->designsAwaitingAnOrder()->count());
    }

    public function test_a_design_that_already_has_an_order_is_not_written_twice(): void
    {
        Storage::fake('local');
        [$officer, $cristal, , $inquiry] = $this->brief();

        $this->actingAs($officer)->post(route('inquiries.designs.store', $inquiry),
            ['label' => 'Rider', 'artist_id' => $cristal->id]);
        $this->actingAs($officer)->post(route('inquiries.layout.complete', $inquiry),
            ['reference_note' => 'Keep the team colours']);

        $design = $inquiry->fresh()->designs->first();

        $this->actingAs($cristal)->post(route('inquiries.designs.submit', $design), [
            'files' => [UploadedFile::fake()->image('drawing.png')],
        ]);
        $this->actingAs($officer)->post(route('inquiries.designs.approve', [$inquiry, $design]));

        $this->actingAs($officer)->post(route('orders.store'), [
            'inquiry_id' => $inquiry->id,
            'inquiry_design_id' => $design->id,
            'order_number' => 'IC2026-ONCE1',
            'due_date' => now()->addWeeks(3)->toDateString(),
            'product_type' => 'round_neck',
            'sizes' => ['M' => 6],
        ])->assertSessionHasNoErrors();

        // A double-click, a stale tab, the back button.
        $this->actingAs($officer)->post(route('orders.store'), [
            'inquiry_id' => $inquiry->id,
            'inquiry_design_id' => $design->id,
            'order_number' => 'IC2026-ONCE2',
            'due_date' => now()->addWeeks(3)->toDateString(),
            'product_type' => 'round_neck',
            'sizes' => ['M' => 6],
        ])->assertSessionHasErrors('inquiry_id');

        $this->assertSame(1, $inquiry->fresh()->orders()->count());
    }

    public function test_a_design_nobody_has_drawn_can_be_taken_off(): void
    {
        Storage::fake('local');
        [$officer, $cristal, , $inquiry] = $this->brief();

        $this->actingAs($officer)->post(route('inquiries.designs.store', $inquiry),
            ['label' => 'Rider', 'how_many' => 2, 'artist_id' => $cristal->id]);
        $this->actingAs($officer)->post(route('inquiries.layout.complete', $inquiry), ['reference_note' => 'Keep the team colours']);

        $designs = $inquiry->fresh()->designs;

        $this->actingAs($cristal)->post(route('inquiries.designs.submit', $designs->first()), [
            'files' => [UploadedFile::fake()->image('d.png')],
        ]);

        // The drawn one stays: taking it off would throw the work away.
        $this->actingAs($officer)
            ->post(route('inquiries.designs.delete', [$inquiry, $designs->first()]))
            ->assertSessionHasErrors('designs');

        $this->actingAs($officer)
            ->post(route('inquiries.designs.delete', [$inquiry, $designs->last()]))
            ->assertSessionHasNoErrors();

        $this->assertCount(1, $inquiry->fresh()->designs);
    }

    public function test_only_a_leader_moves_a_design_once_the_brief_has_gone_out(): void
    {
        [$officer, $cristal, $mick, $inquiry] = $this->brief();
        $leader = User::factory()->create(['job_role' => 'leader', 'is_active' => true]);

        $this->actingAs($officer)->post(route('inquiries.designs.store', $inquiry),
            ['label' => 'Rider', 'artist_id' => $cristal->id]);

        $design = $inquiry->fresh()->designs->first();

        // Still a draft: the officer is arranging the set.
        $this->actingAs($officer)->post(route('inquiries.designs.artist', [$inquiry, $design]),
            ['artist_id' => $mick->id])->assertRedirect()->assertSessionHasNoErrors();

        $this->actingAs($officer)->post(route('inquiries.layout.complete', $inquiry), ['reference_note' => 'Keep the team colours']);

        // Sent: moving started work is the leader's call.
        $this->actingAs($officer)->post(route('inquiries.designs.artist', [$inquiry, $design]),
            ['artist_id' => $cristal->id])->assertForbidden();

        $this->actingAs($leader)->post(route('inquiries.designs.artist', [$inquiry, $design]),
            ['artist_id' => $cristal->id])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame($cristal->id, $inquiry->fresh()->designs->first()->artist_id);
    }

    public function test_a_brief_with_no_list_still_works_as_one_design(): void
    {
        // The officer who does not need the list should not have to use it.
        [$officer, $cristal, , $inquiry] = $this->brief();

        $this->actingAs($officer)->post(route('inquiries.layout.complete', $inquiry),
            ['reference_note' => 'One shirt, keep it simple'])->assertRedirect();

        $designs = $inquiry->fresh()->designs;

        $this->assertCount(1, $designs);
        $this->assertSame('Design 1', $designs->first()->name());
        $this->assertNotNull($designs->first()->artist_id);
        $this->assertNotNull($designs->first()->sent_at);
    }
}
