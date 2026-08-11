<?php

namespace App\Models;

use App\Services\Stations;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One stint on a work station — printer, press, cutting, pairing, sewing or QC.
 * Records who ran it, on which job, and why they came off: a break, a shift
 * change, or the job finishing.
 */
class StationSession extends Model
{
    public const REASONS = [
        'break' => 'On break',
        'shift_change' => 'Shift change',
        'done' => 'Finished',
        // Picked the job up, then put it back — a wrong job order, or called
        // away. The step is untouched and the job returns to the queue.
        'cancelled' => 'Put back',
    ];

    protected $fillable = [
        'station', 'user_id', 'operator_name', 'production_order_id',
        'started_at', 'ended_at', 'end_reason', 'note',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(ProductionOrder::class, 'production_order_id');
    }

    public function isRunning(): bool
    {
        return $this->ended_at === null;
    }

    /** Who actually ran it — the typed name wins, since accounts get shared. */
    public function operator(): string
    {
        return $this->operator_name ?: ($this->user?->name ?? '—');
    }

    /** True when the typed name differs from the account it was logged under. */
    public function loggedUnderDifferentAccount(): bool
    {
        return filled($this->operator_name)
            && $this->user
            && trim(mb_strtolower($this->operator_name)) !== trim(mb_strtolower($this->user->name));
    }

    public function stationLabel(): string
    {
        return Stations::label($this->station);
    }

    public function reasonLabel(): ?string
    {
        return $this->end_reason ? (self::REASONS[$this->end_reason] ?? $this->end_reason) : null;
    }

    /** How long the stint ran, e.g. "1h 20m". */
    public function duration(): string
    {
        $end = $this->ended_at ?? now();
        // Carbon 3 returns a signed float here, so round it into a whole int.
        $mins = (int) round(abs($this->started_at->diffInMinutes($end)));

        return $mins >= 60 ? intdiv($mins, 60).'h '.($mins % 60).'m' : $mins.'m';
    }

    /** The stint currently running on a station, if any. */
    public static function activeOn(string $station): ?self
    {
        return self::with(['user', 'order'])
            ->where('station', $station)
            ->whereNull('ended_at')
            ->latest('id')
            ->first();
    }
}
