<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Inquiry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * What the officer typed goes to the artist, whichever button they press.
 *
 * The brief page had two forms standing next to each other. One listed a
 * design - a name, how many, who draws them, and the notes. The other sent the
 * brief. Pressing the second abandoned everything typed into the first, and
 * nothing said so: the brief went out, the artist opened it, and the
 * instructions were simply not there. The officer had typed them and watched
 * them disappear without a word.
 *
 * "Send to artist" now belongs to the add-design form, so the same press
 * carries the fields, and both buttons list a design through one rule - see
 * Inquiry::addDesigns. Two rules that agree by luck is how they came apart.
 */
class TheNotesGoWithTheBriefTest extends TestCase
{
    use RefreshDatabase;

    private function officer(): User
    {
        return User::factory()->create(['job_role' => User::ROLE_SALES, 'is_active' => true]);
    }

    private function artist(string $name = 'Maru'): User
    {
        return User::factory()->create(['job_role' => User::JOB_ARTIST, 'name' => $name, 'is_active' => true]);
    }

    private function brief(User $officer): Inquiry
    {
        return Inquiry::create([
            'client_id' => Client::create(['name' => 'Zandro', 'last_name' => 'Bernardo'])->id,
            'created_by' => $officer->id,
            'team' => $officer->team,
            'status' => Inquiry::STATUS_OPEN,
            'what_they_want' => 'Jersey',
        ]);
    }

    /**
     * The exact sequence that lost them: type the notes, press Send, never
     * press Add.
     */
    public function test_pressing_send_without_adding_first_keeps_the_notes(): void
    {
        $officer = $this->officer();
        $this->artist();
        $inquiry = $this->brief($officer);

        $this->assertSame(0, $inquiry->designs()->count());

        $this->actingAs($officer)->post(route('inquiries.layout.complete', $inquiry), [
            'label' => 'Jersey',
            'how_many' => 1,
            'description' => "Black red white combination\nFRONT LOGO SMALL LEFT CHEST",
        ])->assertRedirect()->assertSessionHasNoErrors();

        $design = $inquiry->fresh()->designs()->firstOrFail();

        $this->assertSame('Jersey', $design->label, 'the name was dropped');
        $this->assertStringContainsString('Black red white combination', (string) $design->description,
            'the officer typed the notes and they were thrown away');
        $this->assertStringContainsString('FRONT LOGO SMALL LEFT CHEST', (string) $design->description);
        $this->assertNotNull($inquiry->fresh()->layout_sent_at, 'the brief never went out');
    }

    /** And the artist opens it and can read them. */
    public function test_the_artist_can_read_what_was_typed(): void
    {
        $officer = $this->officer();
        $artist = $this->artist('Maru');
        $inquiry = $this->brief($officer);

        $this->actingAs($officer)->post(route('inquiries.layout.complete', $inquiry), [
            'label' => 'Jersey',
            'artist_id' => $artist->id,
            'description' => 'WITH IC LOGO on the back',
        ])->assertRedirect();

        $this->actingAs($artist)->get(route('inquiries.layouts'))
            ->assertOk()
            ->assertSee('WITH IC LOGO on the back');
    }

    /** Several at once, the way the add box does it. */
    public function test_a_count_typed_in_the_box_is_honoured(): void
    {
        $officer = $this->officer();
        $this->artist();
        $inquiry = $this->brief($officer);

        $this->actingAs($officer)->post(route('inquiries.layout.complete', $inquiry), [
            'label' => 'Jersey',
            'how_many' => 3,
            'description' => 'Same cut, three colourways',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $designs = $inquiry->fresh()->designs()->orderBy('position')->get();

        $this->assertCount(3, $designs);
        $this->assertSame(['Jersey 1', 'Jersey 2', 'Jersey 3'], $designs->pluck('label')->all(),
            'listed through a different rule from the one "+ Add design" uses');

        foreach ($designs as $design) {
            $this->assertSame('Same cut, three colourways', $design->description);
        }
    }

    /** The artist named in the box is the one it goes to. */
    public function test_the_artist_chosen_in_the_box_gets_it(): void
    {
        $officer = $this->officer();
        $this->artist('Whoever');
        $chosen = $this->artist('Cristal');
        $inquiry = $this->brief($officer);

        $this->actingAs($officer)->post(route('inquiries.layout.complete', $inquiry), [
            'label' => 'Hoodie',
            'artist_id' => $chosen->id,
            'description' => 'Puff print',
        ])->assertRedirect();

        $this->assertSame($chosen->id, $inquiry->fresh()->designs()->firstOrFail()->artist_id);
    }

    /**
     * Pressing "+ Add design" first and THEN Send is the way it was meant to
     * be used, and it still works - the box is empty by then, so nothing is
     * carried and nothing extra is listed.
     */
    public function test_adding_first_then_sending_lists_it_once(): void
    {
        $officer = $this->officer();
        $this->artist();
        $inquiry = $this->brief($officer);

        $this->actingAs($officer)->post(route('inquiries.designs.store', $inquiry), [
            'label' => 'Jersey',
            'description' => 'Black red white',
        ])->assertRedirect();

        $this->assertSame(1, $inquiry->fresh()->designs()->count());

        $this->actingAs($officer)->post(route('inquiries.layout.complete', $inquiry), [])
            ->assertRedirect()->assertSessionHasNoErrors();

        $designs = $inquiry->fresh()->designs()->get();

        $this->assertCount(1, $designs, 'sending listed a second, empty design');
        $this->assertSame('Black red white', $designs->first()->description);
        $this->assertNotNull($designs->first()->sent_at, 'it never reached the artist');
    }

    /**
     * A brief with nothing at all on it is still refused - the artist opening
     * an empty one has nothing to draw and no way to ask.
     */
    public function test_a_brief_with_nothing_on_it_is_still_refused(): void
    {
        $officer = $this->officer();
        $this->artist();
        $inquiry = $this->brief($officer);

        $this->actingAs($officer)->post(route('inquiries.layout.complete', $inquiry), [])
            ->assertSessionHasErrors('layout');

        $this->assertNull($inquiry->fresh()->layout_sent_at);
        $this->assertSame(0, $inquiry->fresh()->designs()->count());
    }

    /** The page offers the button as part of the form that holds the notes. */
    public function test_the_send_button_belongs_to_the_form_holding_the_notes(): void
    {
        $officer = $this->officer();
        $inquiry = $this->brief($officer);

        $page = $this->actingAs($officer)->get(route('inquiries.layout', $inquiry))
            ->assertOk()->getContent();

        $this->assertStringContainsString('id="addDesignForm"', $page);
        $this->assertStringContainsString('form="addDesignForm"', $page,
            'the Send button is on its own again, so a press would abandon the notes');
        $this->assertStringContainsString(
            'formaction="'.route('inquiries.layout.complete', $inquiry).'"', $page);
    }
}
