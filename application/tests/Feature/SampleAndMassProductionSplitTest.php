<?php

namespace Tests\Feature;

use App\Models\ProductionOrder;
use App\Models\Task;
use App\Models\TechPack;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The sample and the batch are two garments, so they get two sheets.
 *
 * The shop drew one tech pack per order and the sample and the batch shared
 * it. They are not the same garment: the sample is the one the client holds
 * and asks to change, and the batch is what those changes turned into. On one
 * sheet, approving the batch overwrote the only record of what the client had
 * approved, and the floor pressing the batch was reading a sheet that had been
 * edited since they last looked at it.
 *
 * The two are still one job: the batch sheet is opened FROM the approved
 * sample, in the batch stage, and travels the same road for sign-off.
 */
class SampleAndMassProductionSplitTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: User, 1: User, 2: ProductionOrder} */
    private function shop(string $productType = 'round_neck', bool $skipSample = false): array
    {
        $officer = User::factory()->create(['job_role' => User::ROLE_SALES, 'is_active' => true]);
        $artist = User::factory()->create(['job_role' => User::JOB_ARTIST, 'is_active' => true]);

        $order = ProductionOrder::create([
            'order_number' => 'IC2026-SPLIT'.($skipSample ? '-NS' : ''),
            'customer_name' => 'Split Client',
            'product_type' => $productType,
            'quantity' => 40,
            'due_date' => now()->addWeeks(3),
            'created_by' => $officer->id,
            'status' => 'active',
            'skip_sample' => $skipSample,
        ]);

        $order->jobOrder()->create([
            'status' => 'sent_to_artist',
            'created_by' => $officer->id,
            'print_type' => 'dtf',
            'printer' => 'dtf_printer',
        ]);

        $order->buildPipeline([], null);

        return [$officer, $artist, $order->fresh()];
    }

    private function batchStep(ProductionOrder $order): ?Task
    {
        return $order->tasks()
            ->where('department', ProductionOrder::STEP_TECH_PACK_MASSPROD)
            ->first();
    }

    public function test_the_batch_sheet_is_a_step_of_its_own_in_the_batch_stage(): void
    {
        [, , $order] = $this->shop();

        $batch = $this->batchStep($order);

        $this->assertNotNull($batch, 'the batch has no sheet of its own');
        $this->assertSame(10, $batch->stage, 'the batch sheet must wait for the approved sample');
        $this->assertSame(User::JOB_ARTIST, $batch->team);
        // Same road as the sample sheet: artist, account officer, then leader.
        $this->assertSame('sales', $batch->approver_role);
        $this->assertTrue($batch->isTechPackStep());
        $this->assertSame(TechPack::PHASE_MASSPROD, $batch->techPackPhase());
    }

    public function test_the_batch_is_not_printed_before_its_sheet_is_drawn(): void
    {
        [, , $order] = $this->shop();

        $massprod = $order->tasks()->where('department', 'Mass production')->firstOrFail();

        $this->assertSame(
            ProductionOrder::STEP_TECH_PACK_MASSPROD,
            ProductionOrder::STEP_PREREQUISITES['Mass production'],
            'the floor would print the batch off a sheet nobody drew'
        );
        $this->assertSame(10, $massprod->stage);
    }

    public function test_an_order_that_skips_the_sample_keeps_its_one_sheet(): void
    {
        // There was never a second garment, so there is nothing to copy.
        [, , $order] = $this->shop('round_neck', skipSample: true);

        $this->assertNull($this->batchStep($order));
        $this->assertNotNull($order->tasks()->where('department', 'Tech pack')->first());
    }

    public function test_the_batch_sheet_opens_as_a_copy_of_the_approved_sample(): void
    {
        [, , $order] = $this->shop();

        $sample = $order->openTechPack(TechPack::PHASE_SAMPLE);
        $sample->fill([
            'design_name' => 'Team Lewy',
            'tshirt_color' => 'Navy',
            'file_location_notes' => 'SAMPLE FOLDER',
        ])->save();

        $batch = $order->fresh()->openTechPack(TechPack::PHASE_MASSPROD);

        $this->assertTrue($batch->exists);
        $this->assertNotSame($sample->id, $batch->id, 'one row cannot be two sheets');
        $this->assertSame(TechPack::PHASE_MASSPROD, $batch->phase);
        $this->assertSame('Team Lewy', $batch->design_name);
        $this->assertSame('Navy', $batch->tshirt_color);
        $this->assertSame('SAMPLE FOLDER', $batch->file_location_notes);

        // Asked for twice, it is the same sheet - not a fresh copy each time.
        $this->assertSame($batch->id, $order->fresh()->openTechPack(TechPack::PHASE_MASSPROD)->id);
    }

    public function test_correcting_the_batch_leaves_the_approved_sample_alone(): void
    {
        // The whole point: the sample stays as the record of what the client
        // held and approved.
        [, , $order] = $this->shop();

        $sample = $order->openTechPack(TechPack::PHASE_SAMPLE);
        $sample->fill(['tshirt_color' => 'Navy', 'design_name' => 'Team Lewy'])->save();

        $batch = $order->fresh()->openTechPack(TechPack::PHASE_MASSPROD);
        $batch->update(['tshirt_color' => 'Black']);

        $order = $order->fresh();
        $this->assertSame('Navy', $order->techPackFor(TechPack::PHASE_SAMPLE)->tshirt_color);
        $this->assertSame('Black', $order->techPackFor(TechPack::PHASE_MASSPROD)->tshirt_color);
    }

    public function test_each_step_saves_onto_its_own_sheet(): void
    {
        [, $artist, $order] = $this->shop();

        $order->tasks()->where('department', 'Final mockup')->update([
            'status' => 'complete', 'approved_at' => now(), 'assigned_to' => $artist->id,
        ]);

        $sampleStep = $order->tasks()->where('department', 'Tech pack')->firstOrFail();
        $sampleStep->update(['assigned_to' => $artist->id, 'status' => 'in_progress']);

        $this->actingAs($artist)->post(route('tasks.tech-pack', $sampleStep), [
            'file_location_notes' => 'SAMPLE FOLDER',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $batchStep = $this->batchStep($order);
        $batchStep->update(['assigned_to' => $artist->id, 'status' => 'in_progress']);

        $this->actingAs($artist)->post(route('tasks.tech-pack', $batchStep), [
            'file_location_notes' => 'BATCH FOLDER',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $order = $order->fresh();
        $this->assertSame('SAMPLE FOLDER', $order->techPackFor(TechPack::PHASE_SAMPLE)->file_location_notes);
        $this->assertSame('BATCH FOLDER', $order->techPackFor(TechPack::PHASE_MASSPROD)->file_location_notes);
    }

    public function test_the_officer_fills_the_batch_sheet_without_touching_the_sample(): void
    {
        [$officer, , $order] = $this->shop();

        $order->openTechPack(TechPack::PHASE_SAMPLE)->fill(['tshirt_color' => 'Navy'])->save();
        $order->fresh()->openTechPack(TechPack::PHASE_MASSPROD);

        $this->actingAs($officer)->post(
            route('job-orders.update', ['order' => $order, 'phase' => TechPack::PHASE_MASSPROD]),
            ['tshirt_color' => 'Black']
        )->assertRedirect()->assertSessionHasNoErrors();

        $order = $order->fresh();
        $this->assertSame('Navy', $order->techPackFor(TechPack::PHASE_SAMPLE)->tshirt_color);
        $this->assertSame('Black', $order->techPackFor(TechPack::PHASE_MASSPROD)->tshirt_color);
    }

    public function test_the_batch_sheet_is_not_offered_before_the_sample_is_approved(): void
    {
        // Asking for a sheet that has not been opened yet must not quietly
        // create one - it would be a copy of an unapproved sample.
        [$officer, , $order] = $this->shop();

        $order->openTechPack(TechPack::PHASE_SAMPLE)->fill(['tshirt_color' => 'Navy'])->save();

        $this->actingAs($officer)->post(
            route('job-orders.update', ['order' => $order, 'phase' => TechPack::PHASE_MASSPROD]),
            ['tshirt_color' => 'Black']
        )->assertRedirect();

        $order = $order->fresh();
        $this->assertNull($order->techPackFor(TechPack::PHASE_MASSPROD));
        $this->assertSame('Black', $order->techPackFor(TechPack::PHASE_SAMPLE)->tshirt_color);
    }

    /**
     * Skipping the sample does not mean skipping the first half of the job.
     *
     * The window was handed to the mass production steps alone, and everything
     * before stage 10 was left with no deadline at all - the layout, the final
     * mockup, the tech pack, the raw materials, the printing. Those steps went
     * to the floor and to the station boards with no date on them, and nothing
     * could ever call them late. Three of the shop's live jobs were in that
     * state, one of them with every step before mass production undated.
     *
     * Skipping the sample means there is no SAMPLE to put in front of the
     * client. The job is still drawn and still printed.
     */
    public function test_a_skip_sample_order_dates_every_step_not_just_the_batch(): void
    {
        [, , $order] = $this->shop('round_neck', skipSample: true);

        $order->scheduleStepDeadlines(\Illuminate\Support\Carbon::parse('2026-09-01 09:00:00'));
        $order = $order->fresh();

        $steps = $order->tasks()->get();
        $this->assertGreaterThan(0, $steps->count(), 'the pipeline was empty, so this proves nothing');

        $beforeBatch = $steps->filter(
            fn ($t) => $t->stage < ProductionOrder::STAGE_MASS_PRODUCTION
        );

        $this->assertGreaterThan(0, $beforeBatch->count(),
            'a skip-sample order still has steps before mass production');

        // The design run - drawn, mocked up, written up - is worked on every
        // job and must carry a date.
        $design = $beforeBatch->filter(fn ($t) => $t->stage <= ProductionOrder::STAGE_MOCKUP);
        $undated = $design->filter(fn ($t) => ! $t->due_at);

        $this->assertGreaterThan(0, $design->count());
        $this->assertCount(0, $undated,
            'these went to the floor with no deadline: '.$undated->pluck('department')->implode(', '));

        // Stage 3 is the SAMPLE's materials and printing. There is no sample,
        // so there is nothing for them to be due for - the batch has its own
        // at stage 10.
        $sampleOnly = $beforeBatch->filter(fn ($t) => $t->stage > ProductionOrder::STAGE_MOCKUP);
        $this->assertCount(0, $sampleOnly->filter(fn ($t) => $t->due_at),
            'the skipped sample run was given deadlines for work nobody is doing');

        // And the whole run still lands on the client's promise.
        $this->assertSame(
            $order->due_date->toDateString(),
            $steps->filter(fn ($t) => $t->due_at)->max('due_at')->toDateString()
        );
    }

    /** The sample still has its own shorter window when it is not skipped. */
    public function test_a_normal_order_still_splits_into_two_runs(): void
    {
        [, , $order] = $this->shop('round_neck', skipSample: false);

        $order->scheduleStepDeadlines(\Illuminate\Support\Carbon::parse('2026-09-01 09:00:00'));
        $order = $order->fresh();

        $sample = $order->tasks()->get()
            ->filter(fn ($t) => $t->stage < ProductionOrder::STAGE_MASS_PRODUCTION && $t->due_at);

        $this->assertGreaterThan(0, $sample->count());
        $this->assertSame('2026-09-04', $sample->max('due_at')->toDateString(),
            'the sample run should still end three days in, not at the client due date');
    }
}
