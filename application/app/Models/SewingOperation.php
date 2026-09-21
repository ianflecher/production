<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * One operation on one garment, with the minutes it is allowed.
 *
 * The shop's sewing sheet: pick the garment and it says what has to be done to
 * it and how long each one is meant to take. It lived in a spreadsheet, so the
 * person at the machine — the only person who needs it — was the one who could
 * not open it.
 *
 * The list grows. A garment arrives with an operation nobody has timed, and it
 * is written down at the machine, by the person who did it, against their name.
 */
class SewingOperation extends Model
{
    /**
     * The minutes in a working day, as the shop's own sheet has it.
     *
     * It is the "440" printed beside every line: divide it by a garment's total
     * SAM and you get how many one sewer is expected to finish in a day.
     */
    public const MINUTES_A_DAY = 440;

    protected $fillable = ['garment', 'name', 'sam', 'position', 'added_by'];

    protected $casts = [
        'sam' => 'decimal:4',
        'position' => 'integer',
    ];

    /**
     * Garment names as they are written down: one shelf per garment, not one
     * per spelling. "polo shirt" typed at a machine is the POLO SHIRT already
     * on the sheet.
     */
    public static function normaliseGarment(string $garment): string
    {
        return mb_strtoupper(trim(preg_replace('/\s+/', ' ', $garment)));
    }

    /**
     * Every garment on the sheet, in the order the shop wrote them, with
     * anything added since at the end.
     *
     * @return array<int, string>
     */
    public static function garments(): array
    {
        return static::query()
            ->selectRaw('garment, MIN(id) as first_id')
            ->groupBy('garment')
            ->orderBy('first_id')
            ->pluck('garment')
            ->all();
    }

    /**
     * The whole sheet in one query, garment => its operations in order.
     *
     * One query because the station page shows every garment at once and
     * switches between them in the browser: a sewer holding a garment should
     * not wait for a page load to find out what it takes.
     *
     * @return array<string, Collection<int, static>>
     */
    public static function sheet(): array
    {
        $byGarment = static::query()
            ->orderBy('position')
            ->orderBy('id')
            ->get()
            ->groupBy('garment');

        $out = [];

        // Keyed in garments() order rather than the grouping's, so the page
        // reads the way the sheet does.
        foreach (static::garments() as $garment) {
            $out[$garment] = $byGarment->get($garment) ?? new Collection;
        }

        return $out;
    }

    /** @return Collection<int, static> */
    public static function forGarment(string $garment): Collection
    {
        return static::query()
            ->where('garment', static::normaliseGarment($garment))
            ->orderBy('position')
            ->orderBy('id')
            ->get();
    }

    /**
     * The minutes, trimmed of the zeros a decimal column pads on.
     *
     * 0.5039 is a measurement; "0.5039000" is a column width. An operation the
     * shop has never timed shows a dash, because a blank cell in the sheet
     * means nobody knows, not nought.
     */
    public function samLabel(): string
    {
        if ($this->sam === null) {
            return '—';
        }

        return rtrim(rtrim(number_format((float) $this->sam, 4, '.', ''), '0'), '.') ?: '0';
    }

    /** Where the next operation added to a garment goes: the end. */
    public static function nextPosition(string $garment): int
    {
        return (int) static::query()
            ->where('garment', static::normaliseGarment($garment))
            ->max('position') + 1;
    }

    /**
     * What a garment takes altogether, and how many of it a day is.
     *
     * Untimed operations are left out of the total rather than counted as
     * nothing, and the page says how many were skipped so the figure is not
     * read as complete when it is not.
     *
     * @param  Collection<int, static>  $operations
     * @return array{minutes: float, untimed: int, a_day: float|null}
     */
    public static function totals(Collection $operations): array
    {
        $timed = $operations->filter(fn ($o) => $o->sam !== null);
        $minutes = (float) $timed->sum(fn ($o) => (float) $o->sam);

        return [
            'minutes' => $minutes,
            'untimed' => $operations->count() - $timed->count(),
            'a_day' => $minutes > 0 ? self::MINUTES_A_DAY / $minutes : null,
        ];
    }
}
