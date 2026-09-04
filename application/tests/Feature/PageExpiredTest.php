<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "Page expired" on a form somebody just spent five minutes filling in.
 *
 * The token on a page is only good while the session behind it lives. On the
 * LAN the app is plain HTTP, so a browser is also free to serve a page it kept
 * from hours ago — and a restored tab in the morning carries a token the
 * session forgot overnight. Either way the first save of the day is refused
 * after everything has been typed.
 */
class PageExpiredTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_open_page_can_ask_for_a_fresh_token(): void
    {
        $this->get('/session/keep-alive')
            ->assertOk()
            ->assertJsonStructure(['token']);
    }

    /** The login page is where a stale token bites hardest, so guests get it too. */
    public function test_a_guest_on_the_login_page_can_reach_it(): void
    {
        $this->assertGuest();

        $this->get('/session/keep-alive')->assertOk();
    }

    /** Asking is what postpones the session, so the answer is never a cached one. */
    public function test_the_answer_is_never_served_from_cache(): void
    {
        $this->get('/session/keep-alive')
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_pages_are_not_kept_by_the_browser(): void
    {
        $user = User::factory()->create(['job_role' => User::ROLE_SALES, 'is_active' => true]);

        $response = $this->actingAs($user)->get('/dashboard')->assertOk();

        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'),
            'a page kept by the browser is a page carrying a dead token');
    }

    public function test_the_login_page_is_not_kept_either(): void
    {
        $response = $this->get('/login')->assertOk();

        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    /** Every page carries the refresher, because every page can be left open. */
    public function test_a_page_carries_the_token_refresher(): void
    {
        $user = User::factory()->create(['job_role' => User::ROLE_SALES, 'is_active' => true]);

        $this->actingAs($user)->get('/dashboard')
            ->assertOk()
            ->assertSee('session/keep-alive', false);
    }

    /** When it does happen, the staff get a way back rather than "419". */
    public function test_the_expired_page_explains_itself(): void
    {
        $this->assertFileExists(resource_path('views/errors/419.blade.php'));

        $rendered = view('errors.419')->render();

        $this->assertStringContainsString('sat open too long', $rendered);
        $this->assertStringContainsString('Back to what I was typing', $rendered);
    }
}
