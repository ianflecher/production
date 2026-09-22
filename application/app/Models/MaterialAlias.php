<?php

namespace App\Models;

use App\Services\MaterialName;
use Illuminate\Database\Eloquent\Model;

/**
 * Two names for one material.
 *
 * The job order says QA700; the stock sheet says QUIANA. Spelling, case and
 * punctuation are already handled by MaterialName::key(), so this is only for
 * names that are genuinely different words.
 *
 * Which of the pair is "the" name is not a question worth an opinion: a lookup
 * tries it both ways round.
 */
class MaterialAlias extends Model
{
    protected $fillable = ['alias', 'material', 'added_by'];

    /**
     * The index, read once and held for the rest of the request.
     *
     * The requests page asks this once per row, and the table is a handful of
     * pairs rather than a list being read.
     *
     * @var array<string, array<int, string>>|null
     */
    private static ?array $index = null;

    /** @return array<string, array<int, string>> key => the other names' keys */
    public static function index(): array
    {
        if (self::$index !== null) {
            return self::$index;
        }

        $index = [];

        foreach (static::all() as $pair) {
            $a = MaterialName::key($pair->alias);
            $b = MaterialName::key($pair->material);

            if ($a === '' || $b === '' || $a === $b) {
                continue;
            }

            $index[$a][] = $b;
            $index[$b][] = $a;
        }

        return self::$index = array_map(
            fn ($keys) => array_values(array_unique($keys)),
            $index
        );
    }

    /** Read it again — for a test, or anything that writes a pair mid-request. */
    public static function forget(): void
    {
        self::$index = null;
    }

    protected static function booted(): void
    {
        // A pair written is a pair that counts from the next question on.
        static::saved(fn () => self::forget());
        static::deleted(fn () => self::forget());
    }

    /**
     * Every name this material answers to, itself first.
     *
     * @return array<int, string> keys
     */
    public static function keysFor(?string $material): array
    {
        $key = MaterialName::key($material);

        if ($key === '') {
            return [];
        }

        return array_values(array_unique(array_merge([$key], static::index()[$key] ?? [])));
    }
}
