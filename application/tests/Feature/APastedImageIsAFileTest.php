<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Inquiry;
use App\Models\InquiryDesign;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The artist and the officer can paste an image instead of finding a file.
 *
 * The drawing is in the artist's clipboard the moment they finish it, and the
 * reference is in the officer's the moment they are handed it. Saving to the
 * desktop and then finding it again in a file dialog is three steps for
 * something they are already holding, and it leaves a folder full of files
 * called "Screenshot 2026-09-14 101530.png" that nobody ever clears out.
 *
 * What the paste DOES is JavaScript and not testable here. What is testable is
 * that the wiring is on the page: an input that does not carry data-paste-into
 * quietly stops accepting pastes, and the only sign is somebody pressing
 * Ctrl+V and nothing happening. These hold the wiring in place.
 */
class APastedImageIsAFileTest extends TestCase
{
    use RefreshDatabase;

    private function officer(): User
    {
        return User::factory()->create(['job_role' => User::ROLE_SALES, 'is_active' => true]);
    }

    private function artist(): User
    {
        return User::factory()->create(['job_role' => User::JOB_ARTIST, 'is_active' => true]);
    }

    private function brief(User $officer, array $extra = []): Inquiry
    {
        return Inquiry::create(array_merge([
            'client_id' => Client::create(['name' => 'Paste', 'last_name' => 'Client'])->id,
            'created_by' => $officer->id,
            'team' => $officer->team,
            'status' => Inquiry::STATUS_OPEN,
            'what_they_want' => 'Jersey',
        ], $extra));
    }

    /** The artist's hand-back box takes a paste. */
    public function test_the_artist_can_paste_the_drawing_in(): void
    {
        $officer = $this->officer();
        $artist = $this->artist();

        $inquiry = $this->brief($officer, ['layout_sent_at' => now()]);
        $inquiry->designs()->create([
            'label' => 'JERSEY', 'position' => 0,
            'artist_id' => $artist->id,
            'status' => InquiryDesign::STATUS_WITH_ARTIST,
            'sent_at' => now(),
        ]);

        $page = $this->actingAs($artist)->get(route('inquiries.layouts'))->assertOk()->getContent();

        $this->assertStringContainsString('data-paste-into', $page,
            'the hand-back box no longer accepts a pasted drawing');
        $this->assertStringContainsString('input[type="file"][data-paste-into]', $page,
            'the paste script is not on the page, so nothing would happen');
        $this->assertStringContainsString('to paste the drawing in', $page,
            'nothing tells the artist they can paste');
    }

    /** And so does the officer's reference box. */
    public function test_the_officer_can_paste_a_reference_in(): void
    {
        $officer = $this->officer();
        $inquiry = $this->brief($officer);

        $page = $this->actingAs($officer)->get(route('inquiries.layout', $inquiry))
            ->assertOk()->getContent();

        $this->assertStringContainsString('data-paste-into', $page);
        $this->assertStringContainsString('input[type="file"][data-paste-into]', $page);
        $this->assertStringContainsString('to paste it straight in', $page);
    }

    /**
     * Choosing a file by hand still works and has to: a .ai or a .psd cannot
     * be pasted, and those are half of what an artist hands back.
     */
    public function test_choosing_a_file_is_still_offered(): void
    {
        $officer = $this->officer();
        $artist = $this->artist();

        $inquiry = $this->brief($officer, ['layout_sent_at' => now()]);
        $inquiry->designs()->create([
            'label' => 'JERSEY', 'position' => 0,
            'artist_id' => $artist->id,
            'status' => InquiryDesign::STATUS_WITH_ARTIST,
            'sent_at' => now(),
        ]);

        $this->actingAs($artist)->get(route('inquiries.layouts'))
            ->assertOk()
            ->assertSee('type="file"', false)
            ->assertSee('.psd', false);
    }

    /**
     * The script is written once however many boxes are on the page. The
     * artist's queue shows every design they are drawing, so a script per box
     * would be the same listener attached a dozen times and a dozen files
     * attached by one paste.
     */
    public function test_the_paste_script_is_written_once(): void
    {
        $officer = $this->officer();
        $artist = $this->artist();
        $inquiry = $this->brief($officer, ['layout_sent_at' => now()]);

        foreach (['ONE', 'TWO', 'THREE'] as $i => $label) {
            $inquiry->designs()->create([
                'label' => $label, 'position' => $i,
                'artist_id' => $artist->id,
                'status' => InquiryDesign::STATUS_WITH_ARTIST,
                'sent_at' => now(),
            ]);
        }

        $page = $this->actingAs($artist)->get(route('inquiries.layouts'))->assertOk()->getContent();

        // Counted off the input tags themselves: it is a bare attribute, so
        // there is no quoted value to match on.
        $this->assertSame(3, preg_match_all('#<input[^>]*data-paste-into#', $page),
            'expected one paste box per design');
        $this->assertSame(1, substr_count($page, 'input[type="file"][data-paste-into]'),
            'the paste script was written more than once');
    }
}
