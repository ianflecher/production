<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Inquiry;
use App\Models\ProductionOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * A job that owes nothing still has to reach an artist.
 *
 * The layout is drawn on the brief now, so an order written from an approved
 * brief arrives with its Layout step already finished - the row is written
 * straight to the database rather than approved through Task::approve(), and
 * it was that approval, not the row, which opened the stage after it.
 *
 * A job with money owing never showed the gap: Finance confirming the deposit
 * opens the mockup by its own door. A sponsored job priced at zero has no
 * payment coming to open anything, so IC2026-00006 - 19,000 of windbreakers
 * against a 19,500 sponsor discount - sat with a finished layout, a mockup
 * nobody could start, and nothing on any artist's list.
 */
class ASponsoredOrderReachesTheArtistTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: User, 1: User, 2: Inquiry} */
    private function approvedBrief(): array
    {
        Storage::fake('local');

        $officer = User::factory()->create(['job_role' => User::ROLE_SALES, 'is_active' => true]);
        $artist = User::factory()->create(['job_role' => User::JOB_ARTIST, 'is_active' => true]);
        $artist->attendances()->create(['date' => now()->toDateString(), 'status' => 'present']);

        $client = Client::create([
            'name' => 'Rackel Layne', 'last_name' => 'Ortiz', 'company' => 'Kc99 Racing Team',
            'contact_number' => '0975 563 0236', 'created_by' => $officer->id,
        ]);

        $inquiry = Inquiry::create([
            'client_id' => $client->id,
            'created_by' => $officer->id,
            'status' => Inquiry::STATUS_OPEN,
            'what_they_want' => 'Sponsored windbreakers',
        ]);

        $this->actingAs($officer)->post(route('inquiries.designs.store', $inquiry),
            ['label' => 'Windbreaker', 'how_many' => 1, 'artist_id' => $artist->id]);
        $this->actingAs($officer)->post(route('inquiries.layout.complete', $inquiry),
            ['reference_note' => 'Team colours']);

        foreach ($inquiry->fresh()->designs as $design) {
            $this->actingAs($artist)->post(route('inquiries.designs.submit', $design), [
                'files' => [UploadedFile::fake()->image('drawing.png')],
            ]);
            $this->actingAs($officer)->post(route('inquiries.designs.approve', [$inquiry, $design]));
        }

        return [$officer, $artist, $inquiry->fresh()];
    }

    private function order(User $officer, Inquiry $inquiry, string $number, float $discount): ProductionOrder
    {
        $this->actingAs($officer)->post(route('orders.store'), [
            'inquiry_id' => $inquiry->id,
            'order_number' => $number,
            'due_date' => now()->addWeeks(3)->toDateString(),
            'product_type' => 'round_neck',
            'sizes' => ['L' => 10],
            'discount_amount' => $discount,
        ])->assertSessionHasNoErrors();

        return ProductionOrder::where('order_number', $number)->firstOrFail();
    }

    private function mockup(ProductionOrder $order)
    {
        return $order->fresh()->tasks()->where('department', 'like', 'Final mockup%')->first();
    }

    public function test_a_sponsored_order_opens_the_mockup_the_moment_it_is_written(): void
    {
        [$officer, , $inquiry] = $this->approvedBrief();

        // Discounted past its own value, the way a sponsorship is written.
        $order = $this->order($officer, $inquiry, 'IC2026-SPON1', 999999);

        $this->assertTrue($order->owesNothing(), 'the sponsored job is priced at nothing');

        $mockup = $this->mockup($order);

        $this->assertNotNull($mockup);
        $this->assertNotSame('todo', $mockup->status,
            'nothing is owed, so nothing is being waited for - the mockup should be open');
        $this->assertNotNull($mockup->assigned_to, 'and it should be on an artist"s list');
    }

    public function test_a_job_with_money_owing_still_waits_for_finance(): void
    {
        [$officer, , $inquiry] = $this->approvedBrief();

        $order = $this->order($officer, $inquiry, 'IC2026-SPON2', 0);

        $this->assertFalse($order->owesNothing(), 'this one is priced and unpaid');
        $this->assertSame('todo', $this->mockup($order)->status,
            'the deposit gate is unchanged - Finance still opens this one');
    }
}
