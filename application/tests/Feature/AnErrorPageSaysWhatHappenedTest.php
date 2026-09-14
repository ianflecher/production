<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Tests\TestCase;

/**
 * An error page says what happened and gives a way out.
 *
 * What the floor saw was Laravel's bare "404 | NOT FOUND" and
 * "500 | SERVER ERROR" - no explanation, no way back, no hint whether their
 * work was lost. It reads like the system is broken, and the usual next move
 * is to stop and ask somebody whether the system is broken.
 *
 * The 500 in particular has a rule of its own: it must not depend on
 * anything. The app layout calls auth()->user()->canSeeDesignBoard() and
 * counts unread messages out of the database, and the likeliest causes of a
 * 500 are exactly those things failing. An error page that throws its own
 * error leaves a white screen.
 */
class AnErrorPageSaysWhatHappenedTest extends TestCase
{
    use RefreshDatabase;

    private function staff(): User
    {
        return User::factory()->create(['job_role' => 'printer', 'is_active' => true]);
    }

    /* ---------------- 404 ---------------- */

    public function test_a_missing_address_explains_itself(): void
    {
        $this->actingAs($this->staff())
            ->get('/no-such-page-at-all')
            ->assertNotFound()
            ->assertSee('There is nothing at this address')
            ->assertSee('Nothing has been lost');
    }

    /** A guest gets the same page rather than a bare status. */
    public function test_a_guest_gets_the_same_explanation(): void
    {
        $this->get('/no-such-page-at-all')
            ->assertNotFound()
            ->assertSee('There is nothing at this address');
    }

    /**
     * A 404 is also the answer to "does order 51 exist". Naming what was not
     * found is how a not-found page becomes a way to enumerate the shop's work.
     */
    public function test_the_page_does_not_say_what_was_not_found(): void
    {
        $this->actingAs($this->staff())
            ->get('/orders/999999')
            ->assertNotFound()
            ->assertDontSee('999999');
    }

    public function test_a_missing_page_offers_a_way_out(): void
    {
        $this->actingAs($this->staff())
            ->get('/no-such-page-at-all')
            ->assertNotFound()
            ->assertSee('Go to my dashboard');
    }

    /* ---------------- 403 ---------------- */

    /** The refusal page still works after moving its buttons into a partial. */
    public function test_a_refusal_still_explains_itself(): void
    {
        $this->actingAs($this->staff())
            ->get('/finance')
            ->assertForbidden()
            ->assertSee('This one is not yours')
            ->assertSee('Go to my dashboard');
    }

    /* ---------------- 429 ---------------- */

    /**
     * Nearly every throttled route here is public - the client design
     * questionnaires and the job application form - so the reader is as
     * likely to be a client or an applicant as a member of staff.
     */
    public function test_the_throttle_page_assumes_nothing_about_who_is_reading(): void
    {
        $html = view('errors.429')->render();

        $this->assertStringContainsString('Too many tries in a row', $html);
        $this->assertStringNotContainsString('dashboard', $html);
        $this->assertStringNotContainsString('nav-section', $html);
    }

    /**
     * A real number, not "shortly". Somebody told to try again shortly presses
     * the button immediately and earns themselves another 429.
     */
    public function test_the_throttle_page_says_how_long_to_wait(): void
    {
        $exception = new ThrottleRequestsException(
            'Too Many Attempts.', null, ['Retry-After' => 45]
        );

        $html = view('errors.429', ['exception' => $exception])->render();

        $this->assertStringContainsString('45 seconds', $html);
    }

    /** A long wait is said in minutes, because 900 seconds means nothing. */
    public function test_a_long_wait_is_said_in_minutes(): void
    {
        $exception = new ThrottleRequestsException(
            'Too Many Attempts.', null, ['Retry-After' => 900]
        );

        $html = view('errors.429', ['exception' => $exception])->render();

        $this->assertStringContainsString('15 minutes', $html);
    }

    /** And it still renders when nothing said how long. */
    public function test_the_throttle_page_renders_with_no_retry_header(): void
    {
        $html = view('errors.429')->render();

        $this->assertStringContainsString('Wait a short while', $html);
    }

    /** Sending a form twice is the thing people do next, so it is addressed. */
    public function test_the_throttle_page_warns_about_resending(): void
    {
        $this->assertStringContainsString('it may', view('errors.429')->render());
    }

    /* ---------------- 500 ---------------- */

    /**
     * The rule that matters: it renders with no layout, no auth and no
     * database. Rendered directly, because provoking a real 500 in a test
     * would only prove the test harness can throw.
     */
    public function test_the_server_error_page_needs_nothing_to_render(): void
    {
        $html = view('errors.500', ['exception' => new \RuntimeException('anything')])->render();

        $this->assertStringContainsString('Something went wrong at our end', $html);
        $this->assertStringContainsString('Try again', $html);
    }

    /** Even with no exception passed at all. */
    public function test_the_server_error_page_renders_with_no_exception(): void
    {
        $html = view('errors.500')->render();

        $this->assertStringContainsString('Something went wrong at our end', $html);
    }

    /**
     * It must not reach for the app layout, because that is what would fail
     * again. A page that extended it would carry the sidebar's markup.
     */
    public function test_the_server_error_page_does_not_use_the_app_layout(): void
    {
        $html = view('errors.500', ['exception' => new \RuntimeException('x')])->render();

        $this->assertStringNotContainsString('nav-section', $html);
        $this->assertStringContainsString('<!DOCTYPE html>', $html);
    }

    /** A stack trace in front of the floor helps nobody and says too much. */
    public function test_the_server_error_page_shows_no_detail_of_the_failure(): void
    {
        $html = view('errors.500', [
            'exception' => new \RuntimeException('SQLSTATE[42S02]: Base table users not found'),
        ])->render();

        $this->assertStringNotContainsString('SQLSTATE', $html);
        $this->assertStringNotContainsString('users', $html);
    }

    /** But it does carry something to quote, so a log can be searched. */
    public function test_the_server_error_page_carries_a_reference(): void
    {
        $html = view('errors.500', ['exception' => new \RuntimeException('x')])->render();

        $this->assertStringContainsString('Reference', $html);
    }
}
