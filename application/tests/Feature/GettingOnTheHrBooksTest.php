<?php

namespace Tests\Feature;

use App\Models\HrEmployee;
use App\Models\HrRequest;
use App\Models\User;
use App\Support\LeaveBalance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * There was no way onto the HR books, and no way to correct them once on.
 *
 * An employee record was only ever made by an applicant accepting a job
 * offer. That is no use to the people who were already working here when HR
 * arrived: putting them through the pipeline would try to mint a second
 * login, and the email is unique. So they had a login, no employee record,
 * and no My HR page at all - no payslips, no leave, nothing to clock.
 *
 * And nothing on an employment file could be changed after it was written. A
 * salary typed wrong at hiring stayed wrong; the leave allowance, which
 * decides every balance a person is shown, had no way in whatsoever.
 */
class GettingOnTheHrBooksTest extends TestCase
{
    use RefreshDatabase;

    private function hr(): User
    {
        return User::factory()->create(['job_role' => User::JOB_HR, 'is_active' => true]);
    }

    private function longServing(): User
    {
        return User::factory()->create(['job_role' => 'printer', 'is_active' => true]);
    }

    private function onTheBooks(?int $vacation = null): HrEmployee
    {
        return HrEmployee::create([
            'user_id' => $this->longServing()->id,
            'position' => 'Printer',
            'salary' => 18000,
            'salary_period' => 'monthly',
            'vacation_credits' => $vacation,
            'started_on' => now()->subYear(),
        ]);
    }

    /* ---------------- getting on ---------------- */

    public function test_hr_can_put_somebody_already_here_onto_the_books(): void
    {
        $hr = $this->hr();
        $staff = $this->longServing();

        $this->actingAs($hr)->post(route('hr.employees.store'), [
            'user_id' => $staff->id,
            'position' => 'Heat Press',
            'salary' => 16000,
            'salary_period' => 'monthly',
            'started_on' => '2024-03-01',
            'vacation_credits' => 12,
            'sick_credits' => 12,
        ])->assertRedirect();

        $employee = HrEmployee::where('user_id', $staff->id)->firstOrFail();

        $this->assertSame('Heat Press', $employee->position);
        $this->assertSame('16000.00', (string) $employee->salary);
        $this->assertSame(12, $employee->vacation_credits);
    }

    /** The whole point: their own page opens once they are on. */
    public function test_being_put_on_the_books_opens_their_own_page(): void
    {
        $hr = $this->hr();
        $staff = $this->longServing();

        // Before: nothing there, and the record they would need does not exist.
        $this->actingAs($staff)->get(route('hr.my'))->assertOk()->assertSee('Nothing here yet');

        $this->actingAs($hr)->post(route('hr.employees.store'), [
            'user_id' => $staff->id,
            'salary_period' => 'monthly',
            'vacation_credits' => 15,
        ])->assertRedirect();

        $this->actingAs($staff)->get(route('hr.my'))
            ->assertOk()
            ->assertDontSee('Nothing here yet')
            ->assertSee('My time today')
            ->assertSee('My leave');
    }

    /** And with a record they can actually clock. */
    public function test_being_put_on_the_books_lets_them_clock(): void
    {
        $hr = $this->hr();
        $staff = $this->longServing();

        $this->actingAs($staff)->post(route('hr.my.clock-in'))->assertNotFound();

        $this->actingAs($hr)->post(route('hr.employees.store'), [
            'user_id' => $staff->id,
            'salary_period' => 'monthly',
        ])->assertRedirect();

        $this->actingAs($staff)->post(route('hr.my.clock-in'))->assertRedirect();
    }

    /** One record per login. The table says so; the form should say so first. */
    public function test_somebody_already_on_the_books_cannot_be_added_twice(): void
    {
        $hr = $this->hr();
        $employee = $this->onTheBooks();

        $this->actingAs($hr)->post(route('hr.employees.store'), [
            'user_id' => $employee->user_id,
            'salary_period' => 'monthly',
        ])->assertSessionHasErrors('user_id');

        $this->assertSame(1, HrEmployee::where('user_id', $employee->user_id)->count());
    }

    /** So the form never offers somebody who would only produce that error. */
    public function test_the_form_only_offers_people_who_are_not_on_the_books_yet(): void
    {
        $hr = $this->hr();
        $already = $this->onTheBooks();
        $notYet = $this->longServing();

        $this->actingAs($hr)->get(route('hr.employees.create'))
            ->assertOk()
            ->assertSee($notYet->name)
            ->assertDontSee($already->user->name);
    }

    /* ---------------- correcting the file ---------------- */

    public function test_hr_can_correct_a_salary_typed_wrong(): void
    {
        $hr = $this->hr();
        $employee = $this->onTheBooks();

        $this->actingAs($hr)->put(route('hr.employees.update', $employee), [
            'position' => 'Senior Printer',
            'salary' => 21000,
            'salary_period' => 'monthly',
        ])->assertRedirect();

        $employee->refresh();
        $this->assertSame('Senior Printer', $employee->position);
        $this->assertSame('21000.00', (string) $employee->salary);
    }

    /**
     * The gap that made the leave balance unreachable: the column existed and
     * nothing in the app could write it, so every balance was null forever.
     */
    public function test_setting_the_allowance_gives_them_a_balance(): void
    {
        $hr = $this->hr();
        $employee = $this->onTheBooks(vacation: null);

        $this->assertNull(LeaveBalance::for($employee));

        $this->actingAs($hr)->put(route('hr.employees.update', $employee), [
            'salary_period' => 'monthly',
            'vacation_credits' => 15,
        ])->assertRedirect();

        $balance = LeaveBalance::for($employee->fresh());

        $this->assertSame(15, $balance['allowed']);
        $this->assertSame(15.0, $balance['left']);
    }

    /**
     * Blank is "nobody has set one" and zero is "none". Collapsing them would
     * tell somebody they had used up an allowance nobody ever gave them.
     */
    public function test_blank_and_zero_are_not_the_same_answer(): void
    {
        $hr = $this->hr();
        $employee = $this->onTheBooks(vacation: 10);

        $this->actingAs($hr)->put(route('hr.employees.update', $employee), [
            'salary_period' => 'monthly',
            'vacation_credits' => 0,
        ])->assertRedirect();

        $this->assertSame(0, $employee->fresh()->vacation_credits);
        $this->assertSame(0, LeaveBalance::for($employee->fresh())['allowed']);

        // And blanking it takes the allowance away again rather than pinning it.
        $this->actingAs($hr)->put(route('hr.employees.update', $employee), [
            'salary_period' => 'monthly',
            'vacation_credits' => null,
        ])->assertRedirect();

        $this->assertNull($employee->fresh()->vacation_credits);
        $this->assertNull(LeaveBalance::for($employee->fresh()));
    }

    /** Counted off what was granted, so lowering an allowance cannot rewrite it. */
    public function test_lowering_the_allowance_does_not_rewrite_leave_already_granted(): void
    {
        $hr = $this->hr();
        $employee = $this->onTheBooks(vacation: 15);

        $employee->requests()->create([
            'type' => HrRequest::TYPE_LEAVE,
            'starts_on' => now()->format('Y-01-05'),
            'ends_on' => now()->format('Y-01-07'),
            'working_days' => 3,
            'reason' => 'Because.',
            'status' => HrRequest::STATUS_APPROVED,
        ]);

        $this->assertSame(3.0, LeaveBalance::for($employee->fresh())['taken']);

        $this->actingAs($hr)->put(route('hr.employees.update', $employee), [
            'salary_period' => 'monthly',
            'vacation_credits' => 5,
        ])->assertRedirect();

        $balance = LeaveBalance::for($employee->fresh());
        $this->assertSame(3.0, $balance['taken'], 'leave already granted was recounted');
        $this->assertSame(2.0, $balance['left']);
    }

    public function test_they_cannot_have_left_before_they_started(): void
    {
        $hr = $this->hr();
        $employee = $this->onTheBooks();

        $this->actingAs($hr)->put(route('hr.employees.update', $employee), [
            'salary_period' => 'monthly',
            'started_on' => '2025-06-01',
            'ended_on' => '2025-01-01',
        ])->assertSessionHasErrors('ended_on');
    }

    /** The file says plainly when no allowance is set, rather than showing nothing. */
    public function test_the_file_says_when_no_allowance_is_set(): void
    {
        $hr = $this->hr();
        $employee = $this->onTheBooks(vacation: null);

        $this->actingAs($hr)->get(route('hr.employees.show', $employee))
            ->assertOk()
            ->assertSee('No allowance set');
    }

    /* ---------------- who may do it ---------------- */

    public function test_the_shop_floor_cannot_put_people_on_the_books(): void
    {
        $staff = $this->longServing();
        $other = $this->longServing();

        $this->actingAs($staff)->get(route('hr.employees.create'))->assertForbidden();

        $this->actingAs($staff)->post(route('hr.employees.store'), [
            'user_id' => $other->id,
            'salary_period' => 'monthly',
        ])->assertForbidden();

        $this->assertSame(0, HrEmployee::count());
    }

    /** Least of all their own, and least of all their own pay. */
    public function test_nobody_can_edit_their_own_file(): void
    {
        $employee = $this->onTheBooks();

        $this->actingAs($employee->user)->get(route('hr.employees.edit', $employee))->assertForbidden();

        $this->actingAs($employee->user)->put(route('hr.employees.update', $employee), [
            'salary_period' => 'monthly',
            'salary' => 999999,
            'vacation_credits' => 365,
        ])->assertForbidden();

        $employee->refresh();
        $this->assertSame('18000.00', (string) $employee->salary);
        $this->assertNull($employee->vacation_credits);
    }

    public function test_a_super_admin_may_do_it_too(): void
    {
        $admin = User::factory()->create(['job_role' => 'super_admin', 'is_active' => true]);
        $staff = $this->longServing();

        $this->actingAs($admin)->post(route('hr.employees.store'), [
            'user_id' => $staff->id,
            'salary_period' => 'monthly',
        ])->assertRedirect();

        $this->assertSame(1, HrEmployee::where('user_id', $staff->id)->count());
    }
}
