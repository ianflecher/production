<?php

namespace Tests\Feature;

use App\Models\Inquiry;
use App\Models\ProductionOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class InquiryLayoutStepTest extends TestCase
{
    use RefreshDatabase;

    public function test_client_details_lead_to_layout_then_to_the_new_job_order(): void
    {
        Storage::fake('local');
        $officer = User::factory()->create(['job_role' => User::ROLE_SALES, 'is_active' => true]);

        $this->actingAs($officer)->post(route('inquiries.store'), [
            'client_name' => 'Ana',
            'client_last_name' => 'Santos',
            'client_contact' => '09170000000',
            'client_office_address' => 'Angeles City',
            'client_delivery_address' => 'Angeles City',
        ])->assertRedirect(route('inquiries.layout', Inquiry::firstOrFail()));

        $inquiry = Inquiry::firstOrFail();
        $this->actingAs($officer)->get(route('inquiries.index'))
            ->assertOk()
            ->assertSee(route('inquiries.layout', $inquiry), false)
            ->assertSee('Design brief');

        $this->actingAs($officer)->get(route('inquiries.layout', $inquiry))
            ->assertOk()
            ->assertSee('Layout — send to an artist first')
            ->assertSee(route('inquiries.design-brief', $inquiry), false);

        $this->actingAs($officer)->get(route('inquiries.design-brief', $inquiry))
            ->assertOk()->assertSee('Client design questionnaire')
            ->assertSee('Share a form link with the client')
            ->assertSee(route('client.inquiry-design-brief', $inquiry), false);

        $this->get(route('client.inquiry-design-brief', $inquiry))
            ->assertOk()->assertSee('Tell us about your design');
        $this->actingAs($officer)->post(route('inquiries.design-brief.save', $inquiry), [
            'brief' => ['style' => 'Sporty and clean'],
        ])->assertRedirect(route('inquiries.design-brief', $inquiry));

        $this->actingAs($officer)->post(route('inquiries.layout.upload', $inquiry), [
            'reference_files' => [UploadedFile::fake()->image('chatgpt-design.png')],
        ])->assertSessionHasNoErrors();

        $this->actingAs($officer)->post(route('inquiries.layout.complete', $inquiry), [
            'reference_note' => 'Keep the team colours.',
        ])->assertRedirect(route('orders.create', ['inquiry' => $inquiry->id]));

        $this->actingAs($officer)->post(route('orders.store'), [
            'inquiry_id' => $inquiry->id,
            'order_number' => 'IC2026-LAYOUT',
            'product_type' => 'round_neck',
            'sizes' => ['M' => 10],
            'due_date' => now()->addWeeks(3)->toDateString(),
        ])->assertSessionHasNoErrors();

        $order = ProductionOrder::where('order_number', 'IC2026-LAYOUT')->firstOrFail();
        $this->assertSame('Keep the team colours.', $order->jobOrder->reference_note);
        $this->assertSame('Sporty and clean', $order->jobOrder->design_brief['style']);
        $this->assertSame('chatgpt-design.png', $order->jobOrder->referenceFiles()->value('original_name'));
        $this->assertTrue($order->layoutReleased());
    }
}
