<?php

namespace Tests\Feature;

use App\Models\JobOrderFile;
use App\Models\ProductionOrder;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The artist can see the design on the step they are working on.
 *
 * The step page said "the design to make for this order" and gave a button to
 * another page. So the artist opened their task, read that sentence, left to
 * look at the design, and came back to do the work — every time. The picture
 * was one press away from the only screen that needed it.
 *
 * The button stays: that page carries the design full size, the logo files and
 * the client's other attachments. This is the design alone, where the work is.
 */
class TheDesignIsOnTheStepPageTest extends TestCase
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

    private function sales(): User
    {
        return User::factory()->create(['job_role' => User::ROLE_SALES, 'is_active' => true]);
    }

    private function order(User $sales): ProductionOrder
    {
        $this->actingAs($sales)->post('/orders', [
            'order_number' => 'IC2026-04040',
            'client_name' => 'Stephanie',
            'client_last_name' => 'Moto',
            'client_contact' => '0917-000-0000',
            'client_address' => 'Angeles City',
            'due_date' => now()->addWeeks(3)->toDateString(),
            'product_type' => 'round_neck',
            'sizes' => ['M' => 10],
        ]);

        return ProductionOrder::where('order_number', 'IC2026-04040')->firstOrFail();
    }

    private function file(ProductionOrder $order, string $kind, string $name): JobOrderFile
    {
        return JobOrderFile::create([
            'job_order_id' => $order->jobOrder->id,
            'path' => UploadedFile::fake()->image($name)->store('job-order-refs', 'local'),
            'original_name' => $name,
            'kind' => $kind,
            'mime' => 'image/jpeg',
            'size' => 999,
            'uploaded_by' => $order->created_by,
        ]);
    }

    /**
     * The artist's first step, on the artist's desk.
     *
     * Assigned here rather than left to the rotation: a new order's stage-1
     * task is handed out when the stage opens, and what this test is about is
     * what the artist SEES once it is theirs.
     */
    private function step(ProductionOrder $order, User $artist): Task
    {
        $step = $order->tasks()
            ->where('team', User::JOB_ARTIST)
            ->orderBy('sequence')
            ->firstOrFail();

        $step->update(['assigned_to' => $artist->id, 'status' => 'ready']);

        return $step;
    }

    private function imageTag(JobOrderFile $file): string
    {
        return '<img src="'.route('job-order-files.view', $file).'"';
    }

    /* ---------------- the design is on the page ---------------- */

    public function test_the_design_is_shown_on_the_step_and_not_only_linked(): void
    {
        Storage::fake('local');
        $artist = $this->artist('Cristal');
        $order = $this->order($this->sales());
        $design = $this->file($order, 'layout', 'the-drawing.jpg');

        $step = $this->step($order, $artist);
        $this->assertSame($artist->id, $step->assigned_to);

        $this->actingAs($artist)
            ->get(route('tasks.show', $step->id))
            ->assertOk()
            ->assertSee('<h2>The design to make</h2>', false)
            // The picture itself, not a link to the page that has it.
            ->assertSee($this->imageTag($design), false);
    }

    /** And the page that carries the full set is still one press away. */
    public function test_the_full_page_is_still_linked(): void
    {
        Storage::fake('local');
        $artist = $this->artist('Cristal');
        $order = $this->order($this->sales());
        $this->file($order, 'layout', 'the-drawing.jpg');

        $step = $this->step($order, $artist);

        $this->actingAs($artist)
            ->get(route('tasks.show', $step->id))
            ->assertSee(route('tasks.references', $step->id), false);
    }

    /**
     * Nothing drawn yet is the Layout step's own state: there is no approved
     * drawing to work from, so everything the officer put on the order shows
     * rather than an empty box.
     */
    public function test_an_order_with_nothing_drawn_still_shows_its_files(): void
    {
        Storage::fake('local');
        $artist = $this->artist('Cristal');
        $order = $this->order($this->sales());
        $brief = $this->file($order, 'output', 'pasted-20260916-021908.png');

        $step = $this->step($order, $artist);

        $this->actingAs($artist)
            ->get(route('tasks.show', $step->id))
            ->assertSee($this->imageTag($brief), false);
    }

    /**
     * The one that matters, and the one the first cut of this got backwards.
     *
     * "output" is the officer's brief material, uploaded on the INQUIRY and
     * copied onto every order of the brief. "layout" is the artist's own
     * drawing, per order. Gian Lasam's two windbreakers carry the same pasted
     * screenshot as their output file; the drawing that tells CATALYST from
     * CATANIS is the layout one. Show the output and both orders look
     * identical on the step that has to tell them apart.
     */
    public function test_the_drawing_wins_over_the_officers_brief_material(): void
    {
        Storage::fake('local');
        $artist = $this->artist('Cristal');
        $order = $this->order($this->sales());
        $shared = $this->file($order, 'output', 'pasted-20260916-021908.png');
        $drawing = $this->file($order, 'layout', 'Windbreaker 1 - CATALYST.jpg');
        $peg = $this->file($order, 'peg', 'a-peg.jpg');

        $step = $this->step($order, $artist);
        $html = $this->actingAs($artist)
            ->get(route('tasks.show', $step->id))->getContent();

        $this->assertStringContainsString($this->imageTag($drawing), $html);
        $this->assertStringNotContainsString($this->imageTag($shared), $html);
        $this->assertStringNotContainsString($this->imageTag($peg), $html);
    }

    /**
     * And the same answer on the page behind the button, which used to file
     * the approved drawing under "Other files from the client".
     */
    public function test_the_references_page_leads_with_the_drawing_too(): void
    {
        Storage::fake('local');
        $artist = $this->artist('Cristal');
        $order = $this->order($this->sales());
        $this->file($order, 'output', 'pasted-20260916-021908.png');
        $drawing = $this->file($order, 'layout', 'Windbreaker 1 - CATALYST.jpg');

        $step = $this->step($order, $artist);
        $html = $this->actingAs($artist)
            ->get(route('tasks.references', $step->id))->getContent();

        $this->assertStringContainsString('The design to make', $html);
        $this->assertStringContainsString($this->imageTag($drawing), $html);
        // The drawing is the design, not "other files from the client".
        $this->assertStringNotContainsString(
            'Other files from the client (2)', $html);
    }

    /** An order with nothing on it shows no box at all. */
    public function test_an_order_with_no_files_shows_no_design_card(): void
    {
        Storage::fake('local');
        $artist = $this->artist('Cristal');
        $order = $this->order($this->sales());

        $step = $this->step($order, $artist);

        $this->actingAs($artist)
            ->get(route('tasks.show', $step->id))
            ->assertOk()
            ->assertDontSee('<h2>The design to make</h2>', false);
    }

    /* ---------------- and the empty card above it ---------------- */

    /**
     * "Approved design to work from" rendered for any completed artist step,
     * whether or not that step left a drawing behind. On Stephanie Moto's
     * Final mockup the Layout above it was completed with no file uploaded, so
     * the artist got a heading with empty space under it.
     */
    public function test_a_finished_step_with_no_drawing_leaves_no_empty_card(): void
    {
        Storage::fake('local');
        $artist = $this->artist('Cristal');
        $order = $this->order($this->sales());

        $steps = $order->tasks()->where('team', User::JOB_ARTIST)->orderBy('sequence')->get();
        $this->assertGreaterThan(1, $steps->count());

        // The first one finished, and uploaded nothing.
        $first = $steps->first();
        $second = $steps->get(1);
        $first->update(['status' => 'complete', 'assigned_to' => $artist->id]);
        $second->update(['status' => 'ready', 'assigned_to' => $artist->id]);

        $this->actingAs($artist)
            ->get(route('tasks.show', $second->id))
            ->assertOk()
            ->assertDontSee('Approved design to work from', false);
    }
}
