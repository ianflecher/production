<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Something a person asks the office for.
 *
 * Five kinds in one table. They are the same shape — a person, some time, a
 * reason, somebody deciding — and five tables would be five copies of one
 * approval flow that then have to be kept in step with each other.
 */
class HrRequest extends Model
{
    use HasFactory, SoftDeletes;

    public const TYPE_LEAVE = 'leave';

    public const TYPE_SICK = 'sick_leave';

    public const TYPE_EMERGENCY = 'emergency_leave';

    public const TYPE_MATERNITY = 'maternity_leave';

    public const TYPE_PATERNITY = 'paternity_leave';

    public const TYPE_BEREAVEMENT = 'bereavement_leave';

    public const TYPE_STUDY = 'study_leave';

    public const TYPE_UNPAID = 'unpaid_leave';

    public const TYPE_SCHEDULE = 'schedule_change';

    public const TYPE_UNDERTIME = 'undertime';

    public const TYPE_OVERTIME = 'overtime';

    public const TYPE_OFFICIAL_BUSINESS = 'official_business';

    public const TYPES = [
        self::TYPE_LEAVE => 'Vacation leave',
        self::TYPE_SICK => 'Sick leave',
        self::TYPE_EMERGENCY => 'Emergency leave',
        self::TYPE_MATERNITY => 'Maternity leave',
        self::TYPE_PATERNITY => 'Paternity leave',
        self::TYPE_BEREAVEMENT => 'Bereavement leave',
        self::TYPE_STUDY => 'Study leave',
        self::TYPE_UNPAID => 'Leave without pay',
        self::TYPE_SCHEDULE => 'Change of schedule',
        self::TYPE_UNDERTIME => 'Undertime',
        self::TYPE_OVERTIME => 'Overtime',
        self::TYPE_OFFICIAL_BUSINESS => 'Official business',
    ];

    /**
     * Days away from work, whether or not an allowance pays for them.
     *
     * Everything here is counted in working days and shows on the employment
     * file. What separates them is DRAWS_ON below: only two come off a
     * balance the shop grants.
     */
    public const DAYS_AWAY = [
        self::TYPE_LEAVE, self::TYPE_SICK, self::TYPE_EMERGENCY,
        self::TYPE_MATERNITY, self::TYPE_PATERNITY, self::TYPE_BEREAVEMENT,
        self::TYPE_STUDY, self::TYPE_UNPAID,
    ];

    /** The ones the shop does not pay for, so a payroll run can see them. */
    public const UNPAID = [self::TYPE_UNPAID, self::TYPE_STUDY];

    /**
     * The ones that usually come with paper: a medical certificate, a birth
     * certificate, a death certificate, an enrolment letter. Not enforced -
     * somebody off with flu on the day cannot upload anything - but the form
     * asks, because the desk was being sent photographs over chat instead.
     */
    public const WANTS_PAPER = [
        self::TYPE_SICK, self::TYPE_MATERNITY,
        self::TYPE_PATERNITY, self::TYPE_BEREAVEMENT, self::TYPE_STUDY,
    ];

    /** The ones measured in hours rather than days. */
    public const HOURLY = [self::TYPE_UNDERTIME, self::TYPE_OVERTIME, self::TYPE_OFFICIAL_BUSINESS];

    /**
     * Days away from work, and which allowance each one draws on.
     *
     * The shop grants vacation and sick days separately, so they have to be
     * counted separately. Sick leave was filed as plain leave until now,
     * which quietly spent somebody's holiday on being ill.
     */
    public const DRAWS_ON = [
        self::TYPE_LEAVE => 'vacation_credits',
        self::TYPE_SICK => 'sick_credits',
        // And nothing else. Maternity, paternity and bereavement are
        // entitlements in their own right rather than something spent out of a
        // holiday allowance, and study and unpaid leave are not paid for at
        // all. Counting any of them against vacation would take a woman's
        // holiday away for having a baby.
    ];

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_DECLINED = 'declined';

    public const STATUSES = [
        self::STATUS_PENDING => 'Waiting',
        self::STATUS_APPROVED => 'Approved',
        self::STATUS_DECLINED => 'Declined',
    ];

    protected $fillable = [
        'hr_employee_id', 'type', 'starts_on', 'ends_on', 'starts_at', 'ends_at', 'working_days',
        'attachment_path', 'attachment_name',
        'reason', 'status', 'decision_note', 'decided_by', 'decided_at',
    ];

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
            'decided_at' => 'datetime',
            // Cast, or a decimal column comes back as the string "6.00" and
            // every comparison against it is a guess about the driver.
            'working_days' => 'float',
        ];
    }

    /** Who answered it, for the shop-wide leave screen. */
    public function decidedBy()
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    /** The paper that came with it, if any. */
    public function hasAttachment(): bool
    {
        return filled($this->attachment_path);
    }

    /** Whether this kind is days off work rather than an arrangement about one. */
    public function isDaysAway(): bool
    {
        return in_array($this->type, self::DAYS_AWAY, true);
    }

    public function employee()
    {
        return $this->belongsTo(HrEmployee::class, 'hr_employee_id');
    }

    public function decider()
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->type] ?? ucfirst((string) $this->type);
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? ucfirst((string) $this->status);
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    /**
     * How long an hourly request is, in minutes.
     *
     * Overtime, undertime and official business are arrangements about part
     * of a working day, and the length is the whole point of them - an
     * approved overtime with no number attached is a yes the payroll cannot
     * act on. Null when it is not that kind of request, or when the times
     * were left off.
     */
    public function minutes(): ?int
    {
        if (! $this->isHourly() || ! $this->starts_at || ! $this->ends_at) {
            return null;
        }

        $day = ($this->starts_on ?? now())->toDateString();

        $from = Carbon::parse($day)->setTimeFromTimeString($this->starts_at);
        $to = Carbon::parse($day)->setTimeFromTimeString($this->ends_at);

        // Backwards is a mistake, and a mistake should pay nobody.
        return $to->gt($from) ? (int) round($from->diffInMinutes($to)) : 0;
    }

    public function isHourly(): bool
    {
        return in_array($this->type, self::HOURLY, true);
    }

    /** "13 Sep", "13–15 Sep", or "13 Sep, 1:00pm–4:00pm". */
    public function whenLabel(): string
    {
        $from = $this->starts_on?->format('M j') ?? '—';

        if ($this->isHourly()) {
            $hours = trim(
                ($this->starts_at ? date('g:ia', strtotime($this->starts_at)) : '')
                .($this->ends_at ? '–'.date('g:ia', strtotime($this->ends_at)) : '')
            );

            return $hours === '' ? $from : $from.', '.$hours;
        }

        return $this->ends_on && ! $this->ends_on->isSameDay($this->starts_on)
            ? $from.'–'.$this->ends_on->format('M j')
            : $from;
    }
}
