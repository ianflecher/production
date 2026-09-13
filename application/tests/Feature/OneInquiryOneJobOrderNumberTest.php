<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Inquiry;
use App\Models\ProductionOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * One inquiry, one job order number.
 *
 * A brief carries several designs, and each approved design is written up as
 * its own order because each is its own run of work — its own sizes, its own
 * press, its own steps on the floor. They were getting a NUMBER each as well,
 * which is how one client's 830 pieces ended up under IC2026-00005 and
 * IC2026-01174 on the same brief, with nothing on either sheet saying the two
 * belonged together. The second was typed by hand, so it did not even sort
 * next to the first.
 *
 * So the orders stay separate and the number is shared: six orders, one job
 * order number. Two different briefs still may not share one — that is not a
 * job with six parts, it is two jobs nobody can tell apart.
 *
 * The BRIEF is what makes several orders one job, not the client. The same
 * person can have two unrelated enquiries running at once, and those are two
 * jobs with two numbers.
 *
 * It looks at OPEN work only. A brief whose job is delivered or cancelled is
 * finished with.
 */
class OneInquiryOneJobOrderNumberTest extends TestCase
{
    use RefreshDatabase;

    private function officer(): User
    {
        return User::factory()->create(['job_role' => User::ROLE_SALES, 'is_active' => true]);
    }

    private function client(string $name, User $officer): Client
    {
        return Client::create([
            'name' => $name, 'last_name' => 'Client',
            'contact_number' => '09170000000', 'created_by' => $officer->id,
        ]);
    }

    /**
     * A brief with an approved design, ready for a job order to be written.
     *
     * The client is passed in rather than made here, because the whole point
     * of most of these tests is the SAME client coming back with another
     * design. Made fresh each time, they passed while proving nothing.
     */
    private function approvedBrief(User $officer, Client $client): Inquiry
    {
        $artist = User::factory()->create(['job_role' => User::JOB_ARTIST, 'is_active' => true]);

        $inquiry = Inquiry::create([
            'client_id' => $client->id,
            'created_by' => $officer->id,
            'status' => Inquiry::STATUS_OPEN,
            'what_they_want' => 'Shirts',
        ]);

        $this->actingAs($officer)->post(route('inquiries.designs.store', $inquiry),
            ['label' => 'Front', 'how_many' => 1, 'artist_id' => $artist->id]);
        $this->actingAs($officer)->post(route('inquiries.layout.complete', $inquiry),
            ['reference_note' => 'Team colours']);

        foreach ($inquiry->fresh()->designs as $design) {
            $this->actingAs($artist)->post(route('inquiries.designs.submit', $design), [
                'files' => [UploadedFile::fake()->image('drawing.png')],
            ]);
            $this->actingAs($officer)->post(route('inquiries.designs.approve', [$inquiry, $design]));
        }

        return $inquiry->fresh();
    }

    /** One more approved design on a brief that already has some. */
    private function anotherDesign(User $officer, Inquiry $inquiry, string $label): void
    {
        $artist = User::factory()->create(['job_role' => User::JOB_ARTIST, 'is_active' => true]);

        $this->actingAs($officer)->post(route('inquiries.designs.store', $inquiry),
            ['label' => $label, 'how_many' => 1, 'artist_id' => $artist->id]);

        $design = $inquiry->fresh()->designs()->latest('id')->first();

        $this->actingAs($artist)->post(route('inquiries.designs.submit', $design), [
            'files' => [UploadedFile::fake()->image('drawing.png')],
        ]);
        $this->actingAs($officer)->post(route('inquiries.designs.approve', [$inquiry, $design]));
    }

    private function write(User $officer, Inquiry $inquiry, string $number)
    {
        return $this->actingAs($officer)->post(route('orders.store'), [
            'inquiry_id' => $inquiry->id,
            'order_number' => $number,
            'due_date' => now()->addWeeks(3)->toDateString(),
            'product_type' => 'round_neck',
            'sizes' => ['L' => 10],
        ]);
    }

    public function test_the_first_job_order_is_written_as_normal(): void
    {
        $officer = $this->officer();
        $inquiry = $this->approvedBrief($officer, $this->client('Stephanie', $officer));

        $this->write($officer, $inquiry, 'IC2026-00005')->assertSessionHasNoErrors();

        $this->assertDatabaseHas('production_orders', ['order_number' => 'IC2026-00005']);
    }

    public function test_a_second_design_takes_the_same_job_order_number(): void
    {
        $officer = $this->officer();
        $inquiry = $this->approvedBrief($officer, $this->client('Stephanie', $officer));

        $this->write($officer, $inquiry, 'IC2026-00005')->assertSessionHasNoErrors();

        $this->anotherDesign($officer, $inquiry, 'Back');

        // Typing a number of its own does not give it one: this is another
        // part of the job that already exists.
        $this->write($officer, $inquiry->fresh(), 'IC2026-01174')->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('production_orders', ['order_number' => 'IC2026-01174']);
        $this->assertSame(2, ProductionOrder::where('order_number', 'IC2026-00005')->count(),
            'the two designs did not end up under one job order number');
    }

    /** Six parts of one job, one number across all of them. */
    public function test_a_whole_brief_of_designs_sits_under_one_number(): void
    {
        $officer = $this->officer();
        $inquiry = $this->approvedBrief($officer, $this->client('Motostrada', $officer));

        $this->write($officer, $inquiry, 'IC2026-00005')->assertSessionHasNoErrors();

        for ($i = 1; $i <= 5; $i++) {
            $this->anotherDesign($officer, $inquiry, 'Design '.$i);
            $this->write($officer, $inquiry->fresh(), 'IC2026-9000'.$i)->assertSessionHasNoErrors();
        }

        $this->assertSame(6, ProductionOrder::count(), 'each design should still be its own order');
        $this->assertSame(6, ProductionOrder::where('order_number', 'IC2026-00005')->count(),
            'all six parts of the job should carry the job number');
    }

    /**
     * Two different briefs under one number is not a job with six parts, it
     * is two jobs nobody can tell apart.
     */
    public function test_another_jobs_number_cannot_be_taken(): void
    {
        $officer = $this->officer();

        $this->write($officer, $this->approvedBrief($officer, $this->client('Stephanie', $officer)), 'IC2026-00005')
            ->assertSessionHasNoErrors();

        $this->write($officer, $this->approvedBrief($officer, $this->client('Rob', $officer)), 'IC2026-00005')
            ->assertSessionHasErrors('order_number');

        $this->assertSame(1, ProductionOrder::where('order_number', 'IC2026-00005')->count());
    }

    /** Another client is another job, and gets a number of their own. */
    public function test_a_different_client_gets_their_own_number(): void
    {
        $officer = $this->officer();

        $this->write($officer, $this->approvedBrief($officer, $this->client('Stephanie', $officer)), 'IC2026-00005')
            ->assertSessionHasNoErrors();
        $this->write($officer, $this->approvedBrief($officer, $this->client('Rob', $officer)), 'IC2026-00006')
            ->assertSessionHasNoErrors();

        $this->assertSame(2, ProductionOrder::count());
        $this->assertSame(2, ProductionOrder::distinct()->count('order_number'));
    }

    /**
     * A SECOND BRIEF for the same client, while the first job is still open.
     *
     * This is the case that decides what the rule is keyed on. The same person
     * can have two unrelated enquiries running at once - a shirt order this
     * week and a jersey order the next - and they are two jobs. Folding the
     * second into the first's number would say they were one piece of work.
     */
    public function test_a_new_brief_from_the_same_client_is_its_own_job(): void
    {
        $officer = $this->officer();
        $stephanie = $this->client('Stephanie', $officer);

        $this->write($officer, $this->approvedBrief($officer, $stephanie), 'IC2026-00005')
            ->assertSessionHasNoErrors();

        // Nothing to do with the first brief - a separate enquiry entirely.
        $this->write($officer, $this->approvedBrief($officer, $stephanie), 'IC2026-01174')
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('production_orders', ['order_number' => 'IC2026-01174']);
        $this->assertSame(1, ProductionOrder::where('order_number', 'IC2026-00005')->count());
        $this->assertSame(2, ProductionOrder::distinct()->count('order_number'));
    }

    /** A different client's new brief is untouched by any of it. */
    public function test_a_new_brief_from_a_different_client_gets_its_own_number(): void
    {
        $officer = $this->officer();

        $this->write($officer, $this->approvedBrief($officer, $this->client('Stephanie', $officer)), 'IC2026-00005')
            ->assertSessionHasNoErrors();
        $this->write($officer, $this->approvedBrief($officer, $this->client('Rob', $officer)), 'IC2026-01174')
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('production_orders', ['order_number' => 'IC2026-01174']);
    }

    public function test_a_brief_whose_job_is_finished_starts_a_new_number(): void
    {
        $officer = $this->officer();
        $inquiry = $this->approvedBrief($officer, $this->client('Stephanie', $officer));

        $this->write($officer, $inquiry, 'IC2026-00005')->assertSessionHasNoErrors();

        ProductionOrder::where('order_number', 'IC2026-00005')
            ->update(['status' => 'complete', 'completed_at' => now()]);

        // The SAME brief, after its job went out of the door. Written on a new
        // brief this would prove nothing - every new brief gets its own number
        // anyway. It has to be the same one for the "open work only" half of
        // the rule to be doing the work.
        $this->anotherDesign($officer, $inquiry, 'Later');
        $this->write($officer, $inquiry->fresh(), 'IC2026-00020')->assertSessionHasNoErrors();

        $this->assertDatabaseHas('production_orders', ['order_number' => 'IC2026-00020']);
    }

    public function test_a_cancelled_job_also_frees_the_brief(): void
    {
        $officer = $this->officer();
        $inquiry = $this->approvedBrief($officer, $this->client('Stephanie', $officer));

        $this->write($officer, $inquiry, 'IC2026-00005');

        ProductionOrder::where('order_number', 'IC2026-00005')->update(['status' => 'cancelled']);

        $this->anotherDesign($officer, $inquiry, 'Later');
        $this->write($officer, $inquiry->fresh(), 'IC2026-00021')->assertSessionHasNoErrors();

        $this->assertDatabaseHas('production_orders', ['order_number' => 'IC2026-00021']);
    }

    /** Held work is still this brief's live job, so a new part joins it. */
    public function test_a_job_on_hold_still_holds_the_number(): void
    {
        $officer = $this->officer();
        $inquiry = $this->approvedBrief($officer, $this->client('Stephanie', $officer));

        $this->write($officer, $inquiry, 'IC2026-00005');

        ProductionOrder::where('order_number', 'IC2026-00005')->update(['status' => 'on_hold']);

        $this->anotherDesign($officer, $inquiry, 'Back');
        $this->write($officer, $inquiry->fresh(), 'IC2026-00022')->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('production_orders', ['order_number' => 'IC2026-00022']);
        $this->assertSame(2, ProductionOrder::where('order_number', 'IC2026-00005')->count());
    }

    /**
     * A replacement is written BECAUSE the first job exists. It carries its own
     * number on purpose — it is not another part of the job, it is the job
     * being run again.
     */
    public function test_a_replacement_for_a_faulty_job_keeps_its_own_number(): void
    {
        $officer = $this->officer();
        $inquiry = $this->approvedBrief($officer, $this->client('Stephanie', $officer));
        $this->write($officer, $inquiry, 'IC2026-00005')->assertSessionHasNoErrors();

        $original = ProductionOrder::where('order_number', 'IC2026-00005')->firstOrFail();

        $replacement = ProductionOrder::create([
            'order_number' => 'IC2026-00005-R',
            'client_id' => $original->client_id,
            'customer_name' => $original->customer_name,
            'product_type' => $original->product_type,
            'quantity' => 5,
            'due_date' => now()->addWeek(),
            'status' => 'active',
            'created_by' => $officer->id,
            'replaces_order_id' => $original->id,
        ]);

        $this->assertDatabaseHas('production_orders', ['order_number' => 'IC2026-00005-R']);
        $this->assertSame($original->id, $replacement->replaces_order_id);
    }

    /** The lookup the rule reads from. */
    public function test_the_open_job_lookup_only_counts_live_work(): void
    {
        $officer = $this->officer();
        $this->write($officer, $this->approvedBrief($officer, $this->client('Stephanie', $officer)), 'IC2026-00005');

        $order = ProductionOrder::where('order_number', 'IC2026-00005')->firstOrFail();

        $this->assertNotNull(ProductionOrder::openJobFor($order->client_id));

        $order->update(['status' => 'complete']);
        $this->assertNull(ProductionOrder::openJobFor($order->client_id));

        $this->assertNull(ProductionOrder::openJobFor(null), 'a job with no client is nobody\'s');
    }
}
