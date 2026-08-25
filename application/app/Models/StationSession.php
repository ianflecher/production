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

    /**
     * Name shown in the handover log.
     *
     * Sewing is different from the other stations: the shared station account
     * does not identify the people who actually sewed the garment. Those names
     * are recorded seam-by-seam on the job order sheet, so the log should show
     * every distinct sewer credited on that job order.
     */
    public function handoverOperator(): string
    {
        if (str_starts_with($this->station, 'sewing_')) {
            $names = $this->order?->jobOrder?->namesOnSheet(JobOrder::SEWING_STATION_FIELDS) ?? '';

            // Do not fall back to the shared Sewing account. Until somebody
            // is actually written on the sheet, there is no real sewer to name.
            return filled($names) ? $names : '—';
        }

        return $this->operator();
    }

    /** True when the typed name differs from the account it was logged under. */
    public function loggedUnderDifferentAccount(): bool
    {
        return filled($this->operator_name)
            && $this->user
            && trim(mb_strtolower($this->operator_name)) !== trim(mb_strtolower($this->user->name));
    }

    /**
     * What was written down at this station, for this job.
     *
     * Sewing keeps a line per seam — what was done and who did it — and the
     * quality check keeps what was looked at and what was found. Both live on
     * the job rather than on the stint, because they describe the garment and
     * not the shift; the names inside them say who, which is why they are
     * shown as they were written.
     *
     * Anything else records nothing, and gets nothing.
     *
     * @return array<int, string>
     */
    public function workDone(): array
    {
        $jo = $this->order?->jobOrder;

        if (! $jo) {
            return [];
        }

        if (str_starts_with((string) $this->station, 'sewing_')) {
            $lines = collect((array) $jo->sewing_log)
                ->map(function ($row) {
                    $work = trim((string) ($row['work'] ?? ''));
                    $name = trim((string) ($row['name'] ?? ''));

                    if ($work === '') {
                        return null;
                    }

                    return $name === '' ? $work : $work.' — '.$name;
                })
                ->filter()
                ->values()
                ->all();

            if (filled($jo->sewer_notes)) {
                $lines[] = $jo->sewer_notes;
            }

            return $lines;
        }

        if (str_starts_with((string) $this->station, 'qc_')) {
            $checked = trim((string) $jo->qc_notes);
            $by = trim((string) $jo->qc_checked_by);

            if ($checked === '' && $by === '') {
                return [];
            }

            return [$checked === '' ? 'Checked — '.$by : ($by === '' ? $checked : $checked.' — '.$by)];
        }

        return [];
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