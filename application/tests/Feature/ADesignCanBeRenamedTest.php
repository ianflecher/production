<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Inquiry;
use App\Models\InquiryDesign;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A design can be renamed.
 *
 * The name was settled when the design was created and never again. A typo, or
 * a wrong number in a set — EVO COTTON SHIRT 3 where the set runs to two —
 * could only be fixed by deleting the design and adding it back, and deleting
 * is itself refused once anything has been drawn on it. So a wrong name on a
 * design somebody is drawing was permanent.
 *
 * Unlike the description beside it, renaming stays open after the brief has
 * gone to the artist. The description is the instruction they are drawing to
 * and changing it under them moves the goalposts; the name is only what the
 * thing is called on a list, and a wrong one is most worth fixing exactly when
 * somebody is looking at it.
 */
class ADesignCanBeRenamedTest extends TestCase
{
    use RefreshDatabase;

    private function officer(): User
    {
        return User::factory()->create(['job_role' => User::ROLE_SALES, 'is_active' => true]);
    }

    private function design(User $officer, ?string $label = 'EVO COTTON SHIRT 3', bool $sent = false): InquiryDesign
    {
        $inquiry = Inquiry::create([
            'client_id' => Client::create(['name' => 'Stephanie', 'last_name' => 'Moto'])->id,
            'created_by' => $officer->id,
            'team' => $officer->team,
            'status' => Inquiry::STATUS_OPEN,
            'what_they_want' => 'Shirts',
            'layout_sent_at' => $sent ? now() : null,
        ]);

        return $inquiry->designs()->create([
            'label' => $label,
            'position' => 0,
            'artist_id' => User::factory()->create(['job_role' => User::JOB_ARTIST])->id,
            'status' => InquiryDesign::STATUS_WITH_ARTIST,
        ]);
    }

    private function rename(User $as, InquiryDesign $design, ?string $label)
    {
        return $this->actingAs($as)->post(
            route('inquiries.designs.rename', [$design->inquiry_id, $design->id]),
            ['label' => $label]
        );
    }

    public function test_the_officer_can_correct_a_name(): void
    {
        $officer = $this->officer();
        $design = $this->design($officer);

        $this->rename($officer, $design, 'EVO COTTON SHIRT 2')
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame('EVO COTTON SHIRT 2', $design->fresh()->label);
    }

    /**
     * The case this was written for: the brief is already with the artist, and
     * that is exactly when somebody reads the name and sees it is wrong.
     */
    public function test_it_can_still_be_renamed_after_the_brief_has_gone_out(): void
    {
        $officer = $this->officer();
        $design = $this->design($officer, sent: true);

        $this->assertNotNull($design->inquiry->layout_sent_at);

        $this->rename($officer, $design, 'HYBRID SUBLI SHIRT 5')
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame('HYBRID SUBLI SHIRT 5', $design->fresh()->label);
    }

    /** The instructions are not the name, and those stay shut. */
    public function test_the_description_is_still_locked_once_it_has_gone_out(): void
    {
        $officer = $this->officer();
        $design = $this->design($officer, sent: true);

        $this->actingAs($officer)->post(
            route('inquiries.designs.description', [$design->inquiry_id, $design->id]),
            ['description' => 'Actually make it green']
        )->assertStatus(422);
    }

    /** Emptied, it falls back to what an unnamed design has always been called. */
    public function test_clearing_the_name_gives_it_its_default_back(): void
    {
        $officer = $this->officer();
        $design = $this->design($officer);

        $this->rename($officer, $design, '   ')->assertRedirect();

        $design->refresh();
        $this->assertNull($design->label);
        $this->assertNotSame('', trim($design->name()), 'an unnamed design still has to be called something');
    }

    public function test_a_name_longer_than_the_column_is_refused(): void
    {
        $officer = $this->officer();
        $design = $this->design($officer);

        $this->rename($officer, $design, str_repeat('x', 121))
            ->assertSessionHasErrors('label');

        $this->assertSame('EVO COTTON SHIRT 3', $design->fresh()->label);
    }

    /** Somebody else's brief is not theirs to rename. */
    public function test_another_officers_brief_is_refused(): void
    {
        $design = $this->design($this->officer());
        $stranger = User::factory()->create(['job_role' => User::ROLE_SALES, 'is_active' => true]);

        $this->rename($stranger, $design, 'Mine now')->assertForbidden();

        $this->assertSame('EVO COTTON SHIRT 3', $design->fresh()->label);
    }

    public function test_the_box_is_offered_to_the_officer_and_not_to_the_artist_leader(): void
    {
        $officer = $this->officer();
        $design = $this->design($officer);
        $where = route('inquiries.designs.rename', [$design->inquiry_id, $design->id]);

        $this->actingAs($officer)->get(route('inquiries.layout', $design->inquiry))
            ->assertOk()->assertSee($where, false);

        // He is on this page to move work between artists, not to name it.
        $lead = User::factory()->create(['job_role' => User::JOB_ARTIST_LEAD, 'is_active' => true]);
        $this->actingAs($lead)->get(route('inquiries.layout', $design->inquiry))
            ->assertOk()->assertDontSee($where, false);
    }
}
