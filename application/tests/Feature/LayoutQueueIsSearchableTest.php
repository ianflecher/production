<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Inquiry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The layout queue can be searched, like every other list.
 *
 * An artist holding a stack of layouts had no way to find the one a client had
 * just rung about except scrolling. The search runs in the DATABASE, so it
 * reaches the whole queue rather than the part already on screen — and it
 * looks at what somebody is told on the phone: the client, their company, and
 * what they asked for.
 */
class LayoutQueueIsSearchableTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: User, 1: Inquiry, 2: Inquiry} */
    private function queue(): array
    {
        $officer = User::factory()->create(['job_role' => User::ROLE_SALES, 'is_active' => true]);
        $artist = User::factory()->create(['job_role' => User::JOB_ARTIST, 'is_active' => true]);

        $make = function (string $first, string $last, string $company, string $wants) use ($officer, $artist) {
            $client = Client::create([
                'name' => $first, 'last_name' => $last, 'company' => $company,
                'contact_number' => '0917 555 0000', 'created_by' => $officer->id,
            ]);

            return Inquiry::create([
                'client_id' => $client->id,
                'created_by' => $officer->id,
                'what_they_want' => $wants,
                'layout_status' => Inquiry::LAYOUT_WITH_ARTIST,
                'layout_artist_id' => $artist->id,
                'layout_sent_at' => now(),
            ]);
        };

        return [
            $artist,
            $make('Jordan', 'Soriano', 'Aerox Lifestyle', 'Windbreaker with chest print'),
            $make('Mike', 'Calaramo', 'Cebu Runners', 'Singlet for a fun run'),
        ];
    }

    public function test_the_box_is_on_the_page(): void
    {
        [$artist] = $this->queue();

        $this->actingAs($artist)->get(route('inquiries.layouts'))
            ->assertOk()
            ->assertSee('name="q"', false)
            ->assertSee('Search layouts', false);
    }

    public function test_searching_by_client_narrows_the_queue(): void
    {
        [$artist, $wanted, $other] = $this->queue();

        $this->actingAs($artist)->get(route('inquiries.layouts', ['q' => 'Soriano']))
            ->assertOk()
            ->assertSee($wanted->client->fullName())
            ->assertDontSee($other->client->fullName());
    }

    public function test_searching_by_company_finds_it(): void
    {
        [$artist, $wanted, $other] = $this->queue();

        $this->actingAs($artist)->get(route('inquiries.layouts', ['q' => 'Aerox']))
            ->assertOk()
            ->assertSee($wanted->client->fullName())
            ->assertDontSee($other->client->fullName());
    }

    public function test_searching_by_what_they_asked_for_finds_it(): void
    {
        [$artist, $wanted, $other] = $this->queue();

        $this->actingAs($artist)->get(route('inquiries.layouts', ['q' => 'Singlet']))
            ->assertOk()
            ->assertSee($other->client->fullName())
            ->assertDontSee($wanted->client->fullName());
    }

    public function test_a_search_that_finds_nothing_says_so(): void
    {
        // Not the same as an empty queue: "nothing to draw" would read as a
        // finished day rather than a term that matched nothing.
        [$artist] = $this->queue();

        $this->actingAs($artist)->get(route('inquiries.layouts', ['q' => 'nobody at all']))
            ->assertOk()
            ->assertSee('Nothing matches')
            ->assertDontSee('Nothing to draw');
    }

    public function test_no_search_still_shows_the_whole_queue(): void
    {
        [$artist, $one, $two] = $this->queue();

        $this->actingAs($artist)->get(route('inquiries.layouts'))
            ->assertOk()
            ->assertSee($one->client->fullName())
            ->assertSee($two->client->fullName());
    }
}
