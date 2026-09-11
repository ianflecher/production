<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Somebody who filled in the public application form.
 *
 * Not a user account and not staff: a stranger who walked in and typed their
 * details. An account is only made if they are hired, which keeps the shop's
 * user list to people who actually work here.
 */
class HrApplicant extends Model
{
    use HasFactory, SoftDeletes;

    public const STATUS_NEW = 'new';
    public const STATUS_SHORTLISTED = 'shortlisted';
    public const STATUS_INTERVIEWING = 'interviewing';
    public const STATUS_PASSED = 'passed';
    public const STATUS_HIRED = 'hired';
    public const STATUS_SET_ASIDE = 'set_aside';

    public const STATUSES = [
        self::STATUS_NEW => 'New',
        self::STATUS_SHORTLISTED => 'Shortlisted',
        self::STATUS_INTERVIEWING => 'Interviewing',
        self::STATUS_PASSED => 'Passed — ready for an offer',
        self::STATUS_HIRED => 'Hired',
        self::STATUS_SET_ASIDE => 'Set aside',
    ];

    protected $fillable = [
        'first_name', 'last_name', 'contact_number', 'email', 'address',
        'birthdate', 'position', 'about', 'photo_path', 'status', 'applied_at',
    ];

    protected function casts(): array
    {
        return [
            'birthdate' => 'date',
            'applied_at' => 'datetime',
        ];
    }

    public function fullName(): string
    {
        return trim($this->first_name.' '.$this->last_name);
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? ucfirst((string) $this->status);
    }

    public function hasPhoto(): bool
    {
        return filled($this->photo_path);
    }

    public function interviews()
    {
        return $this->hasMany(HrInterview::class)->orderBy('round');
    }

    public function offers()
    {
        return $this->hasMany(HrJobOffer::class)->orderByDesc('id');
    }

    /** Still waiting to be looked at. */
    public static function newCount(): int
    {
        return self::where('status', self::STATUS_NEW)->count();
    }

    /** The round number a new interview would be. Null once three are done. */
    public function nextRound(): ?int
    {
        $used = (int) $this->interviews()->max('round');

        return $used < HrInterview::MAX_ROUNDS ? $used + 1 : null;
    }

    /**
     * Whether another interview may be arranged.
     *
     * Not while one is still outstanding — two rounds booked at once is how an
     * applicant gets called in twice for the same conversation — and not once
     * a round has been failed, because the answer is already no.
     */
    public function canScheduleInterview(): bool
    {
        if ($this->status === self::STATUS_SET_ASIDE) {
            return false;
        }

        if ($this->interviews()->where('outcome', HrInterview::OUTCOME_PENDING)->exists()) {
            return false;
        }

        return $this->nextRound() !== null;
    }
}
