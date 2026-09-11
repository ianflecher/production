<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/** What was put to an applicant: the job, the money, and when they start. */
class HrJobOffer extends Model
{
    use HasFactory, SoftDeletes;

    public const STATUS_DRAFT = 'draft';
    public const STATUS_SENT = 'sent';
    public const STATUS_ACCEPTED = 'accepted';
    public const STATUS_DECLINED = 'declined';

    public const STATUSES = [
        self::STATUS_DRAFT => 'Draft',
        self::STATUS_SENT => 'Given to them',
        self::STATUS_ACCEPTED => 'Accepted',
        self::STATUS_DECLINED => 'Declined',
    ];

    public const PERIODS = [
        'monthly' => 'per month',
        'semi_monthly' => 'per cut-off',
        'daily' => 'per day',
    ];

    protected $fillable = [
        'hr_applicant_id', 'position', 'scope', 'salary', 'salary_period',
        'starts_on', 'terms', 'status', 'sent_at', 'responded_at', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'salary' => 'decimal:2',
            'starts_on' => 'date',
            'sent_at' => 'datetime',
            'responded_at' => 'datetime',
        ];
    }

    public function applicant()
    {
        return $this->belongsTo(HrApplicant::class, 'hr_applicant_id');
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? ucfirst((string) $this->status);
    }

    public function periodLabel(): string
    {
        return self::PERIODS[$this->salary_period] ?? $this->salary_period;
    }

    /** Still being written, so still editable. */
    public function isOpen(): bool
    {
        return in_array($this->status, [self::STATUS_DRAFT, self::STATUS_SENT], true);
    }

    /**
     * The wording the shop hands over. A starting point HR edits — it is a
     * template, not a form, so every line of it can be changed.
     */
    public static function defaultScope(?string $position): string
    {
        return "As {$position}, you are responsible for the work of that bench: "
            ."doing it to the standard the shop sets, keeping to the hours agreed, "
            ."looking after the tools and materials you are given, and telling your "
            ."supervisor early when something is wrong.";
    }

    public static function defaultTerms(): string
    {
        return "Six months probationary, reviewed before it ends.\n"
            ."Paid twice a month.\n"
            ."Government contributions (SSS, PhilHealth, Pag-IBIG) deducted and remitted by the shop.";
    }
}
