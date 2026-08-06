<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\ErrorLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The errors page: what has been failing, without opening the log file. */
class ErrorLogTest extends TestCase
{
    use RefreshDatabase;

    private string $backup = '';

    /** Put a known log in place so the assertions don't depend on real history. */
    private function writeLog(string $contents): void
    {
        $path = ErrorLog::path();

        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }

        if ($this->backup === '' && is_file($path)) {
            $this->backup = file_get_contents($path);
        }

        file_put_contents($path, $contents);
    }

    protected function tearDown(): void
    {
        // Never leave the real log truncated.
        if ($this->backup !== '') {
            file_put_contents(ErrorLog::path(), $this->backup);
        }

        parent::tearDown();
    }

    private function line(string $when, string $env, string $level, string $msg): string
    {
        return "[{$when}] {$env}.{$level}: {$msg}".PHP_EOL;
    }

    public function test_the_same_failure_is_grouped_with_a_count(): void
    {
        $now = now()->format('Y-m-d H:i:s');
        $this->writeLog(
            $this->line($now, 'production', 'ERROR', 'Database has gone away')
            .$this->line($now, 'production', 'ERROR', 'Database has gone away')
            .$this->line($now, 'production', 'ERROR', 'Database has gone away')
        );

        $errors = ErrorLog::recent(7);

        $this->assertCount(1, $errors, 'three identical errors should be one row');
        $this->assertSame(3, $errors[0]['count']);
        $this->assertStringContainsString('Database has gone away', $errors[0]['message']);
    }

    public function test_entries_from_the_test_suite_are_ignored(): void
    {
        $now = now()->format('Y-m-d H:i:s');
        $this->writeLog(
            $this->line($now, 'testing', 'ERROR', 'from a test run')
            .$this->line($now, 'production', 'ERROR', 'a real failure')
        );

        $errors = ErrorLog::recent(7);

        $this->assertCount(1, $errors);
        $this->assertStringContainsString('a real failure', $errors[0]['message']);
    }

    public function test_old_entries_fall_outside_the_window(): void
    {
        $this->writeLog(
            $this->line(now()->subDays(30)->format('Y-m-d H:i:s'), 'production', 'ERROR', 'ancient history')
            .$this->line(now()->format('Y-m-d H:i:s'), 'production', 'ERROR', 'today')
        );

        $recent = ErrorLog::recent(7);
        $this->assertCount(1, $recent);
        $this->assertStringContainsString('today', $recent[0]['message']);

        // Widening the window brings the old one back.
        $this->assertCount(2, ErrorLog::recent(60));
    }

    public function test_the_stack_trace_is_stripped_from_the_message(): void
    {
        $now = now()->format('Y-m-d H:i:s');
        $this->writeLog($this->line(
            $now, 'production', 'ERROR',
            'Undefined constant "lower" {"exception":"[object] (ErrorException(code: 0): ...long trace...)"}'
        ));

        $message = ErrorLog::recent(7)[0]['message'];

        $this->assertStringContainsString('Undefined constant', $message);
        $this->assertStringNotContainsString('exception', $message);
    }

    public function test_warnings_and_info_are_not_reported_as_errors(): void
    {
        $now = now()->format('Y-m-d H:i:s');
        $this->writeLog(
            $this->line($now, 'production', 'INFO', 'just information')
            .$this->line($now, 'production', 'WARNING', 'only a warning')
            .$this->line($now, 'production', 'CRITICAL', 'this one matters')
        );

        $errors = ErrorLog::recent(7);

        $this->assertCount(1, $errors);
        $this->assertSame('CRITICAL', $errors[0]['level']);
    }

    public function test_a_missing_log_file_is_not_a_crash(): void
    {
        $this->writeLog('');

        $this->assertCount(0, ErrorLog::recent(7));
        $this->assertSame(0, ErrorLog::countRecent(7));
    }

    /**
     * The log shrinking must not cost us an error.
     *
     * PHP caches stat results per path, so after a rotate or truncate
     * filesize() can still report the old, larger size. Reading the "tail"
     * from a stale size lands at the start of the file and then throws away
     * the first line -- which is a real error nobody would ever see.
     */
    public function test_a_shrunken_log_still_reports_its_first_error(): void
    {
        $path = ErrorLog::path();
        $now = now()->format('Y-m-d H:i:s');

        // Make it big, and read it so the stat cache holds the large size.
        $this->writeLog(str_repeat($this->line($now, 'production', 'ERROR', 'noise'), 50_000));
        $this->assertGreaterThan(2_097_152, strlen(file_get_contents($path)), 'the log needs to exceed the tail cap');

        // Now it shrinks, exactly as a rotate would leave it.
        file_put_contents($path, str_repeat($this->line($now, 'production', 'ERROR', 'after the rotate'), 3));

        $errors = ErrorLog::recent(7);

        $this->assertCount(1, $errors);
        $this->assertSame(3, $errors[0]['count'], 'the first line was dropped');
    }

    // ---- The page ----------------------------------------------------------

    public function test_a_leader_can_see_the_errors_page(): void
    {
        $this->writeLog($this->line(now()->format('Y-m-d H:i:s'), 'production', 'ERROR', 'something broke badly'));

        $leader = User::factory()->create(['job_role' => User::ROLE_LEADER, 'is_active' => true]);

        $this->actingAs($leader)->get('/system/errors')
            ->assertOk()
            ->assertSee('something broke badly');
    }

    public function test_it_says_so_when_nothing_has_gone_wrong(): void
    {
        $this->writeLog('');

        $leader = User::factory()->create(['job_role' => User::ROLE_LEADER, 'is_active' => true]);

        $this->actingAs($leader)->get('/system/errors')
            ->assertOk()
            ->assertSee('Nothing has gone wrong');
    }

    public function test_ordinary_staff_cannot_see_it(): void
    {
        $agent = User::factory()->create(['job_role' => 'sewing', 'is_active' => true]);
        $sales = User::factory()->create(['job_role' => User::ROLE_SALES, 'is_active' => true]);

        $this->actingAs($agent)->get('/system/errors')->assertForbidden();
        $this->actingAs($sales)->get('/system/errors')->assertForbidden();
    }

    public function test_a_guest_cannot_see_it(): void
    {
        $this->get('/system/errors')->assertRedirect('/login');
    }
}
