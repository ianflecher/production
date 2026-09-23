<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Inquiry;
use App\Models\InquiryDesign;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A brief on the artist's desk says which team sent it.
 *
 * The card said "From Ave". The shop has two account-officer teams and one
 * artist draws for both, so a first name on its own left them working out whose
 * client this was before they could ask anybody anything about it — and there
 * is more than one Ave's worth of ambiguity in a shop this size.
 *
 * "From VIP Ave". "From META Kyson".
 */
class TheArtistSeesWhichTeamSentItTest extends TestCase
{
    use RefreshDatabase;

    /** A brief sent to an artist by an officer on a team. */
    private function brief(?string $team, string $officerName = 'Ave'): array
    {
        $officer = User::factory()->create([
            'name' => $officerName,
            'job_role' => User::ROLE_SALES,
            'team' => $team,
            'is_active' => true,
        ]);

        $artist = User::factory()->create(['job_role' => User::JOB_ARTIST, 'is_active' => true]);

        $client = Client::create([
            'name' => 'Migi', 'last_name' => 'Morte', 'company' => 'Boys Of Bulacan',
            'contact_number' => '0917 555 0000', 'created_by' => $officer->id,
        ]);

        $inquiry = Inquiry::create([
            'client_id' => $client->id,
            'created_by' => $officer->id,
            'what_they_want' => 'JERSEY',
            'layout_status' => Inquiry::LAYOUT_WITH_ARTIST,
            'layout_artist_id' => $artist->id,
            'layout_sent_at' => now(),
        ]);

        $inquiry->designs()->create([
            'position' => 0,
            'artist_id' => $artist->id,
            'status' => InquiryDesign::STATUS_WITH_ARTIST,
            'sent_at' => now(),
        ]);

        return [$artist, $inquiry];
    }

    private function queue(User $artist): string
    {
        return $this->actingAs($artist)
            ->get(route('inquiries.layouts'))
            ->assertOk()->getContent();
    }

    public function test_a_vip_brief_says_vip(): void
    {
        [$artist] = $this->brief('vip', 'Ave');

        $this->assertStringContainsString('From VIP Ave', $this->queue($artist));
    }

    public function test_a_meta_brief_says_meta(): void
    {
        [$artist] = $this->brief('meta', 'Kyson');

        $this->assertStringContainsString('From META Kyson', $this->queue($artist));
    }

    /** An officer on no team is still named, without a blank in front of them. */
    public function test_an_officer_with_no_team_is_just_named(): void
    {
        [$artist] = $this->brief(null, 'Ave');

        $html = $this->queue($artist);

        $this->assertStringContainsString('From Ave', $html);
        $this->assertStringNotContainsString('From  Ave', $html);
    }

    /** And a brief nobody is named on still says where it came from. */
    public function test_a_brief_with_no_officer_says_the_office(): void
    {
        [$artist, $inquiry] = $this->brief('vip');

        $inquiry->update(['created_by' => null]);

        $this->assertStringContainsString('From the office', $this->queue($artist));
    }

    /**
     * Blade does not compile a directive written flush against a word, which is
     * how "Manage stock levels@if (...)" printed itself onto a live page. The
     * team is built as a value for that reason.
     */
    public function test_the_page_prints_no_directives(): void
    {
        [$artist] = $this->brief('vip');

        $html = $this->queue($artist);

        $this->assertStringNotContainsString('@if (', $html);
        $this->assertStringNotContainsString('@endif', $html);
    }
}
