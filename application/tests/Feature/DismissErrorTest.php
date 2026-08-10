<?php

namespace Tests\Feature;

use App\Models\DismissedError;
use App\Models\User;
use App\Services\ErrorLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Clearing an error off the system errors page.
 *
 * The log file is the record and is never edited, so this only remembers that
 * somebody looked. The point of that distinction: a failure that happens AGAIN
 * after being cleared has to come back, or the page becomes a way to silence a
 * problem rather than deal with it.
 */
class DismissErrorTest extends TestCase
{
    use RefreshDatabase;

    private ?string $backup = null;

    private function writeLog(string $contents): void
    {
        $path = ErrorLog::path();

        if ($this->backup === null) {
            $this->backup = is_file($path) ? file_get_contents($path) : '';
        }

        file_put_contents($path, $contents);
    }

    protected function tearDown(): void
    {
        if ($this->backup !== null) {
            file_put_contents(ErrorLog::path(), $this->backup);
        }

        parent::tearDown();
    }

    private function line(string $when, string $msg): string
    {
        return "[{$when}] production.ERROR: {$msg}".PHP_EOL;
    }

    private function leader(): User
    {
        return User::factory()->create(['job_role' => User::ROLE_LEADER, 'is_active' => true]);
    }

    public function test_clearing_one_takes_it_off_the_page(): void
    {
        $this->writeLog(
            $this->line(now()->subHour()->format('Y-m-d H:i:s'), 'Database has gone away')
            .$this->line(now()->subHour()->format('Y-m-d H:i:s'), 'Disk is full')
        );

        $leader = $this->leader();
        $gone = ErrorLog::recent(7)->firstWhere('message', 'Database has gone away');

        $this->actingAs($leader)
            ->post('/system/errors/dismiss', ['fingerprint' => $gone['fingerprint']])
            ->assertRedirect();

        $left = ErrorLog::recent(7)->pluck('message')->all();

        $this->assertNotContains('Database has gone away', $left);
        $this->assertContains('Disk is full', $left, 'clearing one must not clear the rest');
    }

    public function test_the_log_file_itself_is_untouched(): void
    {
        $this->writeLog($this->line(now()->subHour()->format('Y-m-d H:i:s'), 'Database has gone away'));
        $before = file_get_contents(ErrorLog::path());

        $leader = $this->leader();
        $e = ErrorLog::recent(7)->first();

        $this->actingAs($leader)->post('/system/errors/dismiss', ['fingerprint' => $e['fingerprint']]);

        // The record of what happened is not ours to edit.
        $this->assertSame($before, file_get_contents(ErrorLog::path()));
    }

    public function test_it_comes_back_if_it_happens_again(): void
    {
        $this->writeLog($this->line(now()->subHours(2)->format('Y-m-d H:i:s'), 'Database has gone away'));

        $leader = $this->leader();
        $e = ErrorLog::recent(7)->first();
        $this->actingAs($leader)->post('/system/errors/dismiss', ['fingerprint' => $e['fingerprint']]);

        $this->assertCount(0, ErrorLog::recent(7));

        // The same failure, after it was cleared. That is a new problem.
        $this->travel(1)->minute();
        $this->writeLog(
            $this->line(now()->subHours(2)->format('Y-m-d H:i:s'), 'Database has gone away')
            .$this->line(now()->format('Y-m-d H:i:s'), 'Database has gone away')
        );

        $this->assertCount(1, ErrorLog::recent(7), 'a failure that recurred must not stay hidden');
    }

    public function test_clearing_the_same_thing_twice_is_harmless(): void
    {
        $this->writeLog($this->line(now()->subHour()->format('Y-m-d H:i:s'), 'Database has gone away'));

        $leader = $this->leader();
        $e = ErrorLog::recent(7)->first();

        $this->actingAs($leader)->post('/system/errors/dismiss', ['fingerprint' => $e['fingerprint']]);
        $this->actingAs($leader)->post('/system/errors/dismiss', ['fingerprint' => $e['fingerprint']]);

        $this->assertSame(1, DismissedError::count(), 'one row per failure, not one per click');
    }

    public function test_it_records_who_cleared_it(): void
    {
        $this->writeLog($this->line(now()->subHour()->format('Y-m-d H:i:s'), 'Database has gone away'));

        $leader = $this->leader();
        $e = ErrorLog::recent(7)->first();

        $this->actingAs($leader)->post('/system/errors/dismiss', ['fingerprint' => $e['fingerprint']]);

        $this->assertSame($leader->id, DismissedError::first()->dismissed_by);
    }

    public function test_the_page_offers_a_way_to_clear(): void
    {
        $this->writeLog($this->line(now()->subHour()->format('Y-m-d H:i:s'), 'Database has gone away'));

        $this->actingAs($this->leader())->get('/system/errors')
            ->assertOk()
            ->assertSee('name="fingerprint"', false)
            ->assertSee('Clear', false);
    }

    public function test_ordinary_staff_cannot_clear_anything(): void
    {
        $agent = User::factory()->create(['job_role' => 'sewing', 'is_active' => true]);

        $this->actingAs($agent)
            ->post('/system/errors/dismiss', ['fingerprint' => str_repeat('a', 64)])
            ->assertForbidden();

        $this->assertSame(0, DismissedError::count());
    }

    public function test_a_nonsense_fingerprint_is_refused(): void
    {
        $this->actingAs($this->leader())
            ->post('/system/errors/dismiss', ['fingerprint' => 'nope'])
            ->assertSessionHasErrors('fingerprint');
    }
}
