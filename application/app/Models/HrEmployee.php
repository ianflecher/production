<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A person the shop employs, as HR keeps them.
 *
 * The login stays in users, where the rest of the system already looks for it.
 * This row is everything HR knows that the shop floor has no business with —
 * what they are paid, when they started — and it is the single join between
 * the HR module and the rest of the app.
 */
class HrEmployee extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'user_id', 'hr_applicant_id', 'hr_job_offer_id',
        'position', 'salary', 'salary_period',
        // What they are allowed off in a year. Null means nobody has set one,
        // which is not the same as none — see Support\LeaveBalance.
        'vacation_credits', 'sick_credits',
        'started_on', 'ended_on',
    ];

    protected function casts(): array
    {
        return [
            'salary' => 'decimal:2',
            'started_on' => 'date',
            'ended_on' => 'date',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function applicant()
    {
        return $this->belongsTo(HrApplicant::class, 'hr_applicant_id');
    }

    public function offer()
    {
        return $this->belongsTo(HrJobOffer::class, 'hr_job_offer_id');
    }

    public function payslips()
    {
        return $this->hasMany(HrPayslip::class)->orderByDesc('period_end');
    }

    public function incidents()
    {
        return $this->hasMany(HrIncident::class)->orderByDesc('occurred_on');
    }

    public function loans()
    {
        return $this->hasMany(HrLoan::class)->orderByDesc('borrowed_on');
    }

    public function requests()
    {
        return $this->hasMany(HrRequest::class)->orderByDesc('starts_on');
    }

    public function isCurrent(): bool
    {
        return $this->ended_on === null;
    }

    /** What they still owe across every loan. */
    public function loanBalance(): float
    {
        return round($this->loans->sum(fn (HrLoan $l) => $l->balance()), 2);
    }

    /** The HR record for a login, if that person has one. */
    public static function forUser(?User $user): ?self
    {
        return $user
            ? self::with(['payslips', 'incidents', 'loans.payments', 'requests'])
                ->where('user_id', $user->id)
                ->first()
            : null;
    }
}
