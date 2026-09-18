<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Inquiry;
use App\Models\InquiryDesign;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The brief says who drew each design, in every state.
 *
 * The name was printed on the two states where a design is still moving — not
 * sent yet, and being drawn — and left off the two where it has stopped:
 * handed back to the client, and approved. So the moment an artist finished
 * something, the page stopped saying who had done it, which is exactly when
 * somebody asks.
 *
 * A design that went round again says so too: "rev 2/3" beside the name,
 * wherever it has got to.
 */
class TheBriefSaysWhoDrewEachDesignTest extends TestCase
{
    use RefreshDatabase;

    private function officer(): User
    {
        return User::factory()->create(['job_role' => User::ROLE_SALES, 'is_active' => true]);
    }

    private function briefWith(string $status, int $revisions = 0, bool $sent = true): array
    {
        $officer = $this->officer();
        $artist = User::factory()->create([
            'name' => 'Cristal', 'job_role' => User::JOB_ARTIST, 'is_active' => true,
        ]);

        $client = Client::create([
            'name' => 'Street', 'last_name' => 'GP',
            'contact_number' => '0917-000-0000', 'created_by' => $officer->id,
        ]);

        $inquiry = Inquiry::create([
            'client_id' => $client->id,
            'what_they_want' => 'Cotton tee',
            'created_by' => $officer->id,
            'status' => Inquiry::STATUS_OPEN,
            'layout_sent_at' => $sent ? now()->subDay() : null,
        ]);

        InquiryDesign::create([
            'inquiry_id' => $inquiry->id,
            'position' => 0,
            'label' => 'COTTON TEE',
            'artist_id' => $artist->id,
            'status' => $status,
            'revision_count' => $revisions,
            'sent_at' => $sent ? now()->subDay() : null,
            'submitted_at' => in_array($status, [InquiryDesign::STATUS_SUBMITTED, InquiryDesign::STATUS_APPROVED], true)
                ? now()->subHours(2) : null,
            'approved_at' => $status === InquiryDesign::STATUS_APPROVED ? now()->subHour() : null,
        ]);

        return [$officer, $inquiry];
    }

    private function pills(User $officer, Inquiry $inquiry): string
    {
        $html = $this->actingAs($officer)
            ->get(route('inquiries.layout', $inquiry))
            ->assertOk()->getContent();

        preg_match_all('#<span class="design-pill[^"]*">(.*?)</span>#s', $html, $m);

        return html_entity_decode(preg_replace('/\s+/', ' ', strip_tags(implode(' | ', $m[1]))));
    }

    /* ---------------- the two that were missing ---------------- */

    /** The one in the screenshot: handed back, and no word about who drew it. */
    public function test_a_design_with_the_client_says_who_drew_it(): void
    {
        [$officer, $inquiry] = $this->briefWith(InquiryDesign::STATUS_SUBMITTED);

        $this->assertStringContainsString('With the client', $this->pills($officer, $inquiry));
        $this->assertStringContainsString('drawn by Cristal', $this->pills($officer, $inquiry));
    }

    public function test_an_approved_design_says_who_drew_it(): void
    {
        [$officer, $inquiry] = $this->briefWith(InquiryDesign::STATUS_APPROVED);

        $pills = $this->pills($officer, $inquiry);

        $this->assertStringContainsString('Approved', $pills);
        $this->assertStringContainsString('drawn by Cristal', $pills);
    }

    /* ---------------- the two that already did ---------------- */

    public function test_a_design_being_drawn_still_names_its_artist(): void
    {
        [$officer, $inquiry] = $this->briefWith(InquiryDesign::STATUS_WITH_ARTIST);

        $this->assertStringContainsString('Cristal is drawing it', $this->pills($officer, $inquiry));
    }

    public function test_an_unsent_design_still_names_its_artist(): void
    {
        // "Not sent yet" is the BRIEF status, not merely a null sent_at.
        [$officer, $inquiry] = $this->briefWith(InquiryDesign::STATUS_BRIEF, sent: false);

        $pills = $this->pills($officer, $inquiry);

        $this->assertStringContainsString('Not sent yet', $pills);
        $this->assertStringContainsString('Cristal', $pills);
    }

    /* ---------------- and the rounds ---------------- */

    /** A design that went back and round again says so, wherever it has got to. */
    public function test_a_redrawn_design_shows_its_round(): void
    {
        [$officer, $inquiry] = $this->briefWith(InquiryDesign::STATUS_SUBMITTED, revisions: 2);

        $pills = $this->pills($officer, $inquiry);

        $this->assertStringContainsString('drawn by Cristal', $pills);
        $this->assertStringContainsString('rev 2/'.InquiryDesign::REVISION_LIMIT, $pills);
    }

    /** A first draft is not dressed up as a revision. */
    public function test_a_first_draft_shows_no_round(): void
    {
        [$officer, $inquiry] = $this->briefWith(InquiryDesign::STATUS_SUBMITTED);

        $this->assertStringNotContainsString('rev ', $this->pills($officer, $inquiry));
    }

    /* ---------------- and no Blade leaking ---------------- */

    /**
     * Blade does not compile a directive written flush against a word, which
     * is how "Manage stock levels@if (...)" printed itself onto a live page.
     * These pills are built as values for that reason.
     */
    public function test_the_page_prints_no_directives(): void
    {
        [$officer, $inquiry] = $this->briefWith(InquiryDesign::STATUS_APPROVED, revisions: 1);

        $html = $this->actingAs($officer)->get(route('inquiries.layout', $inquiry))->getContent();

        $this->assertStringNotContainsString('@if (', $html);
        $this->assertStringNotContainsString('@endif', $html);
    }
}
