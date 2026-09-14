<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

class Attendance extends Model
{
    protected $fillable = [
        'user_id', 'date', 'status', 'set_by',
        // When they came and went, and how that measured against the shift.
        // The minutes are kept rather than derived, so changing the shift
        // does not re-score days already recorded - see Support\Shift.
        'time_in', 'time_out',
        'late_minutes', 'undertime_minutes', 'overtime_minutes',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'late_minutes' => 'integer',
            'undertime_minutes' => 'integer',
            'overtime_minutes' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Somebody who is here but arrived past the shift's grace. */
    public function wasLate(): bool
    {
        return $this->status === 'present' && $this->late_minutes > 0;
    }

    /** "8:14 AM", or a dash when nobody clocked. */
    public function clockedIn(): string
    {
        return $this->time_in ? Carbon::parse($this->time_in)->format('g:i A') : '—';
    }

    public function clockedOut(): string
    {
        return $this->time_out ? Carbon::parse($this->time_out)->format('g:i A') : '—';
    }
}
