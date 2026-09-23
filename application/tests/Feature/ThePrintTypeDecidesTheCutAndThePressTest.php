<?php

namespace Tests\Feature;

use App\Models\JobOrder;
use App\Models\ProductionOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * How a job is cut and pressed follows from what it is printed with.
 *
 * Sublimation is printed onto paper and rolled onto the cloth under heat, so it
 * is laser cut and pressed on the roller. Everything else is cut by hand and
 * goes under the small press.
 *
 * Those were two dropdowns on the production form, defaulted from the print
 * type and overridable, which meant the shop could be told a job was
 * sublimation and cut by hand — two answers to one question, with no way of
 * telling which was the mistake. They are gone; the print type answers.
 *
 * Embroidery is the exception, and barely one: it is not printed, so nothing is
 * pressed onto it. Its machine is the embroidery machine, which is what puts
 * the job on the embroidery bench — see IC2026-00009 for what happens when that
 * is not said.
 */
class ThePrintTypeDecidesTheCutAndThePressTest extends TestCase
{
    use RefreshDatabase;

    private function jobOrder(array $attributes): JobOrder
    {
        $officer = User::factory()->create(['job_role' => User::ROLE_SALES, 'is_active' => true]);

        $order = ProductionOrder::create([
            'order_number' => 'IC2026-RT'.random_int(1000, 9999),
            'customer_name' => 'Route Co',
            'product_type' => 'round_neck',
            'quantity' => 30,
            'due_date' => now()->addWeeks(2),
            'created_by' => $officer->id,
            'status' => 'active',
        ]);

        return $order->jobOrder()->create($attributes + [
            'status' => 'draft', 'created_by' => $officer->id,
        ]);
    }

    /* ---------------- sublimation ---------------- */

    /** However the shop happens to have written it that day. */
    public function test_sublimation_is_laser_cut_and_rolled(): void
    {
        foreach (['FULL SUBLIMATION', 'SUBLIMATION', 'full sublimation', 'Sublimation Print'] as $typed) {
            $routing = $this->jobOrder(['print_type' => $typed])->printRouting();

            $this->assertSame('laser', $routing['cutting'], $typed.' was not laser cut');
            $this->assertSame('roller_press', $routing['fabric_press'], $typed.' did not go on the roller');
        }
    }

    /* ---------------- everything else ---------------- */

    public function test_anything_else_is_cut_by_hand_and_pressed_small(): void
    {
        foreach (['DTF', 'Eco Solvent', 'Vinyl', 'Silkscreen', 'N/A'] as $typed) {
            $routing = $this->jobOrder(['print_type' => $typed])->printRouting();

            $this->assertSame('manual', $routing['cutting'], $typed.' was not cut by hand');
            $this->assertSame('small_press', $routing['fabric_press'], $typed.' did not go on the small press');
        }
    }

    /**
     * A tech pack nobody has filled in yet is not sublimation, so it takes the
     * ordinary route rather than no route at all. Half the live job orders have
     * this box empty.
     */
    public function test_a_blank_print_type_takes_the_ordinary_route(): void
    {
        $routing = $this->jobOrder(['print_type' => null])->printRouting();

        $this->assertSame('manual', $routing['cutting']);
        $this->assertSame('small_press', $routing['fabric_press']);
    }

    /* ---------------- embroidery keeps its own machine ---------------- */

    public function test_an_embroidered_job_goes_to_the_embroidery_machine(): void
    {
        foreach (
            [['print_type' => 'EMBROIDERY'], ['print_type' => 'N/A', 'printer' => 'embroidery']] as $attributes
        ) {
            $routing = $this->jobOrder($attributes)->printRouting();

            $this->assertSame('embroidery', $routing['fabric_press']);
            $this->assertSame('manual', $routing['cutting']);
        }
    }

    /* ---------------- and the form stopped asking ---------------- */

    public function test_the_form_no_longer_asks_for_either(): void
    {
        $jobOrder = $this->jobOrder(['print_type' => 'FULL SUBLIMATION', 'printer' => 'atexco']);
        $order = $jobOrder->order;
        $officer = User::find($order->created_by);

        $html = $this->actingAs($officer)
            ->get(route('job-orders.production', $order))
            ->assertOk()->getContent();

        $this->assertStringNotContainsString('name="cutting_type"', $html);
        $this->assertStringNotContainsString('name="fabric_press"', $html);

        // It says what they come to instead, and why.
        $this->assertStringContainsString('Cutting and fabric press', $html);
        $this->assertStringContainsString('Laser', $html);
        $this->assertStringContainsString('Roller press', $html);
        $this->assertStringContainsString('Full Sublimation', $html);
    }

    /** And saving writes both, off the print type, without being sent either. */
    public function test_saving_writes_both_from_the_print_type(): void
    {
        $jobOrder = $this->jobOrder(['print_type' => 'FULL SUBLIMATION', 'printer' => 'atexco']);
        $order = $jobOrder->order;
        $officer = User::find($order->created_by);

        $this->actingAs($officer)
            ->post(route('job-orders.production.update', $order), ['raw_materials' => ['Cotton']])
            ->assertSessionHasNoErrors();

        $this->assertSame('roller_press', $order->fresh()->jobOrder->fabric_press);
        $this->assertSame('laser', $order->fresh()->cutting_type);
    }

    /**
     * And a posted cutting or press is not a way round it. The fields are gone
     * from the form; anything still sending them is a stale tab or somebody
     * poking at it.
     */
    public function test_a_posted_cut_or_press_is_ignored(): void
    {
        $jobOrder = $this->jobOrder(['print_type' => 'FULL SUBLIMATION', 'printer' => 'atexco']);
        $order = $jobOrder->order;
        $officer = User::find($order->created_by);

        $this->actingAs($officer)
            ->post(route('job-orders.production.update', $order), [
                'raw_materials' => ['Cotton'],
                'cutting_type' => 'manual',
                'fabric_press' => 'small_press',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('roller_press', $order->fresh()->jobOrder->fabric_press);
        $this->assertSame('laser', $order->fresh()->cutting_type);
    }
}
