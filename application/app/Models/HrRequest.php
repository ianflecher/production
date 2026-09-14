<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

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
    public const TYPE_SCHEDULE = 'schedule_change';
    public const TYPE_UNDERTIME = 'undertime';
    public const TYPE_OVERTIME = 'overtime';
    public const TYPE_OFFICIAL_BUSINESS = 'official_business';

    public const TYPES = [
        self::TYPE_LEAVE => 'Leave',
        self::TYPE_SCHEDULE => 'Change of schedule',
        self::TYPE_UNDERTIME => 'Undertime',
        self::TYPE_OVERTIME => 'Overtime',
        self::TYPE_OFFICIAL_BUSINESS => 'Official business',
    ];

    /** The ones measured in hours rather than days. */
    public const HOURLY = [self::TYPE_UNDERTIME, self::TYPE_OVERTIME, self::TYPE_OFFICIAL_BUSINESS];

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
