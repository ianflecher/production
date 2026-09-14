<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A day the shop is shut.
 *
 * Kept as rows rather than a list in code because the Philippine calendar
 * moves: the regular holidays shift each year and the special non-working
 * days are proclaimed, sometimes weeks ahead. A list in code means a deploy
 * every time the palace announces one.
 */
class Holiday extends Model
{
    public const KIND_REGULAR = 'regular';

    public const KIND_SPECIAL = 'special';

    public const KINDS = [
        self::KIND_REGULAR => 'Regular holiday',
        self::KIND_SPECIAL => 'Special non-working day',
    ];

    protected $fillable = ['date', 'name', 'kind', 'created_by'];

    protected function casts(): array
    {
        return ['date' => 'date'];
    }

    /**
     * The dates the shop is shut between two days, as plain Y-m-d strings.
     *
     * One query for a whole range, because the caller is usually walking a
     * fortnight a day at a time and asking per day is a query per day.
     *
     * @return array<int, string>
     */
    public static function between($from, $to): array
    {
        return static::query()
            ->whereBetween('date', [
                Carbon::parse($from)->toDateString(),
                Carbon::parse($to)->toDateString(),
            ])
            ->pluck('date')
            ->map(fn ($d) => Carbon::parse($d)->toDateString())
            ->all();
    }

    public function isRegular(): bool
    {
        return $this->kind === self::KIND_REGULAR;
    }
}
