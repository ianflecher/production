<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/** One round of interview with one applicant, by one named person. */
class HrInterview extends Model
{
    use HasFactory, SoftDeletes;

    public const MAX_ROUNDS = 3;

    public const OUTCOME_PENDING = 'pending';
    public const OUTCOME_PASSED = 'passed';
    public const OUTCOME_FAILED = 'failed';

    public const OUTCOMES = [
        self::OUTCOME_PENDING => 'Not yet done',
        self::OUTCOME_PASSED => 'Passed',
        self::OUTCOME_FAILED => 'Did not pass',
    ];

    protected $fillable = [
        'hr_applicant_id', 'round', 'interviewer_id', 'scheduled_at',
        'outcome', 'notes', 'completed_at', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function applicant()
    {
        return $this->belongsTo(HrApplicant::class, 'hr_applicant_id');
    }

    public function interviewer()
    {
        return $this->belongsTo(User::class, 'interviewer_id');
    }

    public function isDone(): bool
    {
        return $this->outcome !== self::OUTCOME_PENDING;
    }

    public function outcomeLabel(): string
    {
        return self::OUTCOMES[$this->outcome] ?? ucfirst((string) $this->outcome);
    }

    /** Interviews this person still has to do. */
    public static function pendingFor(User $user)
    {
        return self::with('applicant')
            ->where('interviewer_id', $user->id)
            ->where('outcome', self::OUTCOME_PENDING)
            ->orderBy('scheduled_at')
            ->get();
    }
}
