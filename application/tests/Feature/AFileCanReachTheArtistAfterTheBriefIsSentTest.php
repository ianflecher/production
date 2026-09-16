<?php

namespace Tests\Feature;

use App\Models\AppNotification;
use App\Models\Client;
use App\Models\Inquiry;
use App\Models\InquiryDesign;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * A design file can still reach the artist after the brief has been sent.
 *
 * Sending shut the upload box, and the client does not stop sending things
 * when the artist starts drawing — a photo of the logo, the right shade, the
 * spelling of a name on a jersey. With nowhere to put them the officer sent
 * them somewhere the system cannot see, and the artist kept working from the
 * brief as it was.
 *
 * ADDING only. Removing stays shut once the brief is sent, and that is what
 * the lock is actually for: an artist halfway through must not have a file
 * taken out from under them. A file arriving alongside what they already have
 * takes nothing away; a file disappearing does.
 *
 * And a late file that nobody is told about is a file nobody opens, so the
 * artists drawing the brief are notified — the same rule sendLayout() uses
 * when the brief first goes out.
 */
class AFileCanReachTheArtistAfterTheBriefIsSentTest extends TestCase
{
    use RefreshDatabase;

    private function officer(): User
    {
        return User::factory()->create(['job_role' => User::ROLE_SALES, 'is_active' => true]);
    }

    /** A brief already with two artists, one file on it. */
    private function sentBrief(User $officer, array $artistNames = ['Cristal']): Inquiry
    {
        $client = Client::create([
            'name' => 'Consignee', 'last_name' => 'Co',
            'contact_number' => '0917-000-0000', 'created_by' => $officer->id,
        ]);

        $inquiry = Inquiry::create([
            'client_id' => $client->id,
            'what_they_want' => 'Windbreakers',
            'created_by' => $officer->id,
            'layout_sent_at' => now()->subHour(),
            'layout_files' => [[
                'path' => 'inquiry-layouts/first.png',
                'original_name' => 'first.png',
                'mime' => 'image/png',
                'size' => 100,
                'uploaded_by' => $officer->id,
                'kind' => 'output',
            ]],
        ]);

        foreach ($artistNames as $i => $name) {
            $artist = User::factory()->create([
                'name' => $name, 'job_role' => User::JOB_ARTIST, 'is_active' => true,
            ]);

            InquiryDesign::create([
                'inquiry_id' => $inquiry->id,
                'position' => $i,
                'artist_id' => $artist->id,
                'status' => InquiryDesign::STATUS_WITH_ARTIST,
            ]);
        }

        return $inquiry->fresh();
    }

    private function upload(User $officer, Inquiry $inquiry): \Illuminate\Testing\TestResponse
    {
        Storage::fake('local');

        return $this->actingAs($officer)->post(route('inquiries.layout.upload', $inquiry), [
            'reference_files' => [UploadedFile::fake()->image('logo-photo.jpg')],
        ]);
    }

    /* ---------------- it goes through ---------------- */

    /** The bug itself: the upload was refused outright. */
    public function test_a_file_can_be_added_after_the_brief_is_sent(): void
    {
        $officer = $this->officer();
        $inquiry = $this->sentBrief($officer);

        $this->upload($officer, $inquiry)->assertSessionHasNoErrors();

        $this->assertCount(2, $inquiry->fresh()->layout_files);
    }

    /** What was already there is untouched. */
    public function test_the_file_the_artist_already_has_is_left_alone(): void
    {
        $officer = $this->officer();
        $inquiry = $this->sentBrief($officer);

        $this->upload($officer, $inquiry);

        $files = $inquiry->fresh()->layout_files;

        $this->assertSame('first.png', $files[0]['original_name']);
        $this->assertSame('logo-photo.jpg', $files[1]['original_name']);
    }

    /** A late arrival is stamped, so it can be told from the rest. */
    public function test_a_late_file_is_marked_as_late(): void
    {
        $officer = $this->officer();
        $inquiry = $this->sentBrief($officer);

        $this->upload($officer, $inquiry);

        $files = $inquiry->fresh()->layout_files;

        $this->assertNull($files[0]['added_at'] ?? null);
        $this->assertNotNull($files[1]['added_at']);
    }

    /** Uploading before it is sent is unchanged, and marks nothing. */
    public function test_a_file_added_before_sending_is_not_marked(): void
    {
        $officer = $this->officer();
        $inquiry = $this->sentBrief($officer);
        $inquiry->update(['layout_sent_at' => null, 'layout_files' => null]);

        $this->upload($officer, $inquiry->fresh())->assertSessionHasNoErrors();

        $files = $inquiry->fresh()->layout_files;

        $this->assertCount(1, $files);
        $this->assertNull($files[0]['added_at']);
    }

    /* ---------------- the artist hears about it ---------------- */

    public function test_the_artist_is_told(): void
    {
        $officer = $this->officer();
        $inquiry = $this->sentBrief($officer, ['Cristal']);

        $this->upload($officer, $inquiry);

        $artist = User::where('name', 'Cristal')->first();
        $note = AppNotification::where('user_id', $artist->id)->latest('id')->first();

        $this->assertNotNull($note, 'the file landed on the brief and nobody was told');
        $this->assertStringContainsString('brief you are drawing', $note->title);
    }

    /** Two artists on one brief are told once each, not once per design. */
    public function test_each_artist_is_told_once(): void
    {
        $officer = $this->officer();
        $inquiry = $this->sentBrief($officer, ['Cristal', 'Mick']);

        // A second design for Cristal, so she has two on this brief.
        InquiryDesign::create([
            'inquiry_id' => $inquiry->id,
            'position' => 9,
            'artist_id' => User::where('name', 'Cristal')->value('id'),
            'status' => InquiryDesign::STATUS_WITH_ARTIST,
        ]);

        $this->upload($officer, $inquiry->fresh());

        foreach (['Cristal', 'Mick'] as $name) {
            $id = User::where('name', $name)->value('id');
            $this->assertSame(1, AppNotification::where('user_id', $id)->count(),
                $name.' was told more than once about one upload');
        }
    }

    /** Nobody is told when the brief has not gone out yet. */
    public function test_nobody_is_told_before_the_brief_is_sent(): void
    {
        $officer = $this->officer();
        $inquiry = $this->sentBrief($officer);
        $inquiry->update(['layout_sent_at' => null]);

        $this->upload($officer, $inquiry->fresh());

        $this->assertSame(0, AppNotification::count());
    }

    /* ---------------- but removing is still shut ---------------- */

    /**
     * The half of the lock that was right. An artist drawing from a brief
     * must not have a file vanish from under them.
     */
    public function test_a_file_still_cannot_be_removed_after_sending(): void
    {
        $officer = $this->officer();
        $inquiry = $this->sentBrief($officer);

        $this->actingAs($officer)
            ->post(route('inquiries.layout.file.delete', [$inquiry, 'index' => 0]))
            ->assertSessionHasErrors('layout');

        $this->assertCount(1, $inquiry->fresh()->layout_files);
    }
}
