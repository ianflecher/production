<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\HrEmployee;
use App\Models\User;
use App\Support\Shift;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Attendance knew whether somebody came, never when.
 *
 * A present/absent flag answers "was the work covered today" and nothing
 * else. Who is habitually late, whether the overtime somebody filed was
 * actually worked, how much of a day an early leaver missed - all of it was
 * memory and argument, and the shop's own HRIS had the answer while this did
 * not.
 *
 * Clocked by the person themselves. A leader marking a whole team at ten
 * would record everybody as two hours late, which is worse than not knowing.
 */
class TheClockKnowsWhenTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Pin the shift so a change to the config cannot quietly rewrite what
        // these tests mean.
        config(['shift.start' => '08:00', 'shift.end' => '17:00', 'shift.grace_minutes' => 15]);
    }

    private function staff(): HrEmployee
    {
        return HrEmployee::create([
            'user_id' => User::factory()->create(['job_role' => 'printer', 'is_active' => true])->id,
            'position' => 'Printer',
            'salary' => 18000,
            'salary_period' => 'monthly',
            'started_on' => now()->subYear(),
        ]);
    }

    /* ---------------- measuring a day ---------------- */

    public function test_on_time_is_not_late(): void
    {
        $this->assertSame(0, Shift::metrics('08:00', null)['late']);
        $this->assertFalse(Shift::isLate('07:45'));
    }

    /** A quarter of an hour is traffic. Inside the grace is not late at all. */
    public function test_inside_the_grace_is_not_late(): void
    {
        $this->assertFalse(Shift::isLate('08:14'));
        $this->assertSame(0, Shift::metrics('08:15', null)['late']);
    }

    /**
     * Past the grace and the lateness counts from the START of the shift, not
     * from the end of the grace. The grace forgives an arrival or it does not.
     */
    public function test_past_the_grace_counts_from_the_start_of_the_shift(): void
    {
        $this->assertTrue(Shift::isLate('08:20'));
        $this->assertSame(20, Shift::metrics('08:20', null)['late']);
        $this->assertSame(90, Shift::metrics('09:30', null)['late']);
    }

    public function test_leaving_early_is_undertime_and_staying_is_overtime(): void
    {
        $early = Shift::metrics('08:00', '16:30');
        $this->assertSame(30, $early['undertime']);
        $this->assertSame(0, $early['overtime']);

        $long = Shift::metrics('08:00', '19:00');
        $this->assertSame(0, $long['undertime']);
        $this->assertSame(120, $long['overtime']);
    }

    /** On the dot is neither. */
    public function test_leaving_on_time_is_neither(): void
    {
        $m = Shift::metrics('08:00', '17:00');
        $this->assertSame(0, $m['undertime']);
        $this->assertSame(0, $m['overtime']);
    }

    /** Both can be true of one day: late in AND late out. */
    public function test_a_late_arrival_can_still_work_overtime(): void
    {
        $m = Shift::metrics('09:00', '18:00');
        $this->assertSame(60, $m['late']);
        $this->assertSame(60, $m['overtime']);
        $this->assertSame(0, $m['undertime']);
    }

    /** A missing clock measures nothing, rather than counting as zero worked. */
    public function test_a_missing_clock_measures_nothing(): void
    {
        $this->assertSame(['late' => 0, 'undertime' => 0, 'overtime' => 0], Shift::metrics(null, null));
        $this->assertSame(0, Shift::metrics('09:30', null)['undertime']);
        $this->assertFalse(Shift::isLate(null));
    }

    /**
     * Both times must be read onto the same day, or a 17:00 shift end gets
     * compared with a time-out Carbon parsed onto some other date.
     */
    public function test_the_two_clocks_are_read_onto_one_day(): void
    {
        $m = Shift::metrics('08:00:00', '17:45:00', '2026-01-05');
        $this->assertSame(45, $m['overtime']);
    }

    /* ---------------- clocking in ---------------- */

    public function test_clocking_in_records_the_time_and_marks_them_present(): void
    {
        $employee = $this->staff();

        Carbon::setTestNow(Carbon::parse('2026-09-14 08:05:00'));

        $this->actingAs($employee->user)->post(route('hr.my.clock-in'))->assertRedirect();

        $row = Attendance::where('user_id', $employee->user_id)->firstOrFail();

        $this->assertSame('present', $row->status);
        $this->assertSame('08:05:00', $row->time_in);
        $this->assertSame(0, $row->late_minutes);
    }

    public function test_clocking_in_late_writes_the_minutes_down(): void
    {
        $employee = $this->staff();

        Carbon::setTestNow(Carbon::parse('2026-09-14 08:47:00'));

        $this->actingAs($employee->user)->post(route('hr.my.clock-in'))
            ->assertRedirect()
            ->assertSessionHas('success', fn ($said) => str_contains($said, '47 minutes late'));

        $this->assertSame(47, Attendance::where('user_id', $employee->user_id)->firstOrFail()->late_minutes);
    }

    /**
     * A second press is somebody checking the page, not somebody arriving
     * again. Letting it through would move an arrival later and wipe out a
     * lateness already recorded.
     */
    public function test_clocking_in_twice_does_not_move_the_arrival(): void
    {
        $employee = $this->staff();

        Carbon::setTestNow(Carbon::parse('2026-09-14 08:47:00'));
        $this->actingAs($employee->user)->post(route('hr.my.clock-in'))->assertRedirect();

        Carbon::setTestNow(Carbon::parse('2026-09-14 11:00:00'));
        $this->actingAs($employee->user)->post(route('hr.my.clock-in'))
            ->assertRedirect()
            ->assertSessionHas('success', fn ($said) => str_contains($said, 'already clocked in'));

        $row = Attendance::where('user_id', $employee->user_id)->firstOrFail();
        $this->assertSame('08:47:00', $row->time_in);
        $this->assertSame(47, $row->late_minutes);
    }

    /**
     * The leader's override says "absent" for somebody who then turns up.
     * Clocking in IS being present, whatever the row said before.
     */
    public function test_clocking_in_overrides_a_leaders_absent_mark(): void
    {
        $employee = $this->staff();

        Carbon::setTestNow(Carbon::parse('2026-09-14 09:00:00'));

        Attendance::create([
            'user_id' => $employee->user_id,
            'date' => '2026-09-14',
            'status' => 'absent',
        ]);

        $this->actingAs($employee->user)->post(route('hr.my.clock-in'))->assertRedirect();

        $this->assertSame('present', Attendance::where('user_id', $employee->user_id)->firstOrFail()->status);
    }

    /* ---------------- clocking out ---------------- */

    public function test_clocking_out_closes_the_day_off(): void
    {
        $employee = $this->staff();

        Carbon::setTestNow(Carbon::parse('2026-09-14 08:00:00'));
        $this->actingAs($employee->user)->post(route('hr.my.clock-in'))->assertRedirect();

        Carbon::setTestNow(Carbon::parse('2026-09-14 18:30:00'));
        $this->actingAs($employee->user)->post(route('hr.my.clock-out'))->assertRedirect();

        $row = Attendance::where('user_id', $employee->user_id)->firstOrFail();
        $this->assertSame('18:30:00', $row->time_out);
        $this->assertSame(90, $row->overtime_minutes);
        $this->assertSame(0, $row->undertime_minutes);
    }

    public function test_leaving_early_is_written_down_too(): void
    {
        $employee = $this->staff();

        Carbon::setTestNow(Carbon::parse('2026-09-14 08:00:00'));
        $this->actingAs($employee->user)->post(route('hr.my.clock-in'))->assertRedirect();

        Carbon::setTestNow(Carbon::parse('2026-09-14 15:00:00'));
        $this->actingAs($employee->user)->post(route('hr.my.clock-out'))->assertRedirect();

        $this->assertSame(120, Attendance::where('user_id', $employee->user_id)->firstOrFail()->undertime_minutes);
    }

    /** There is no arrival to close off. */
    public function test_clocking_out_without_clocking_in_does_nothing(): void
    {
        $employee = $this->staff();

        Carbon::setTestNow(Carbon::parse('2026-09-14 17:30:00'));

        $this->actingAs($employee->user)->post(route('hr.my.clock-out'))
            ->assertRedirect()
            ->assertSessionHas('success', fn ($said) => str_contains($said, 'Clock in first'));

        $this->assertSame(0, Attendance::where('user_id', $employee->user_id)->count());
    }

    /* ---------------- whose clock it is ---------------- */

    /**
     * There is no id in these routes, so there is no number to change to
     * somebody else's. The only thing to prove is that it lands on the
     * person who pressed it.
     */
    public function test_a_clock_lands_on_the_person_who_pressed_it(): void
    {
        $mine = $this->staff();
        $theirs = $this->staff();

        Carbon::setTestNow(Carbon::parse('2026-09-14 08:00:00'));
        $this->actingAs($mine->user)->post(route('hr.my.clock-in'))->assertRedirect();

        $this->assertSame(1, Attendance::where('user_id', $mine->user_id)->count());
        $this->assertSame(0, Attendance::where('user_id', $theirs->user_id)->count());
    }

    /** Somebody HR has never set up has no timekeeping to do. */
    public function test_somebody_with_no_employee_record_cannot_clock(): void
    {
        $stranger = User::factory()->create(['job_role' => 'printer', 'is_active' => true]);

        $this->actingAs($stranger)->post(route('hr.my.clock-in'))->assertNotFound();
        $this->assertSame(0, Attendance::count());
    }

    /* ---------------- what the pages say ---------------- */

    public function test_their_own_page_offers_the_right_button(): void
    {
        $employee = $this->staff();

        Carbon::setTestNow(Carbon::parse('2026-09-14 08:00:00'));

        $this->actingAs($employee->user)->get(route('hr.my'))
            ->assertOk()->assertSee('Clock in')->assertDontSee('Clock out');

        $this->actingAs($employee->user)->post(route('hr.my.clock-in'));

        $this->actingAs($employee->user)->get(route('hr.my'))
            ->assertOk()->assertSee('Clock out');
    }

    public function test_hr_can_see_a_habit_forming(): void
    {
        $hr = User::factory()->create(['job_role' => User::JOB_HR, 'is_active' => true]);
        $employee = $this->staff();

        foreach (['2026-09-08', '2026-09-09', '2026-09-10'] as $day) {
            Carbon::setTestNow(Carbon::parse($day.' 08:40:00'));
            $this->actingAs($employee->user)->post(route('hr.my.clock-in'));
        }

        Carbon::setTestNow(Carbon::parse('2026-09-14 09:00:00'));

        $this->actingAs($hr)->get(route('hr.employees.show', $employee))
            ->assertOk()
            ->assertSee('Timekeeping')
            ->assertSee('days recorded')
            ->assertSeeInOrder(['>3<', 'days recorded'], false)
            ->assertSeeInOrder(['>3<', 'late'], false)
            ->assertSeeInOrder(['>120<', 'min late in all'], false);   // 3 x 40
    }

    /**
     * A day the shift was measured against should not re-score itself when
     * somebody edits the shift afterwards. The minutes are kept, not derived.
     */
    public function test_changing_the_shift_does_not_rewrite_a_day_already_recorded(): void
    {
        $employee = $this->staff();

        Carbon::setTestNow(Carbon::parse('2026-09-14 08:40:00'));
        $this->actingAs($employee->user)->post(route('hr.my.clock-in'))->assertRedirect();

        $this->assertSame(40, Attendance::where('user_id', $employee->user_id)->firstOrFail()->late_minutes);

        // The shop moves to a nine o'clock start.
        config(['shift.start' => '09:00']);

        $this->assertSame(40, Attendance::where('user_id', $employee->user_id)->firstOrFail()->late_minutes,
            'a day already recorded quietly re-scored itself when the shift changed');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }
}
