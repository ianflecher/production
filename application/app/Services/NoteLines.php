<?php

namespace App\Services;

use Illuminate\Support\Str;

/**
 * Turn a note into the list of instructions it actually is.
 *
 * An officer writing up a revision types every change into one box, and it
 * came out on the artist's screen as a single unbroken paragraph — six things
 * to do, read as one sentence, with the fourth easy to miss. This splits it
 * back into the items the person meant.
 *
 * What the officer typed is trusted first: if they used separate lines, or
 * numbered or dashed them, those are the items. Only a note written as one
 * run of prose is split on its own sentence endings.
 */
class NoteLines
{
    /**
     * Abbreviations whose full stop does not end a sentence.
     *
     * Addresses turn up in these notes constantly — "Sto. Rosario St., Angeles
     * City" is one place, not three instructions.
     */
    private const ABBREVIATIONS = [
        'sto', 'sta', 'st', 'mr', 'mrs', 'ms', 'dr', 'blvd', 'ave', 'brgy',
        'no', 'pcs', 'pc', 'approx', 'etc', 'vs', 'jr', 'sr',
    ];

    /**
     * @return array<int, string> the note as separate items; empty if blank
     */
    public static function bullets(?string $note): array
    {
        $note = trim((string) $note);

        if ($note === '') {
            return [];
        }

        // 1. What the officer typed as separate lines is already the answer.
        $lines = preg_split('/\R+/u', $note) ?: [];
        $lines = array_values(array_filter(array_map(
            fn ($line) => self::strip($line),
            $lines
        ), fn ($line) => $line !== ''));

        if (count($lines) > 1) {
            return $lines;
        }

        // 2. One run of prose: split on sentence endings, keeping the
        //    punctuation off the front of the next item.
        $single = $lines[0] ?? '';
        $parts = preg_split('/(?<=[.!?])\s+/u', $single) ?: [];

        $items = [];
        foreach ($parts as $part) {
            $part = trim($part);

            if ($part === '') {
                continue;
            }

            // "Sto." and friends did not end anything — glue it back on.
            if ($items !== [] && self::endsWithAbbreviation(end($items))) {
                $items[array_key_last($items)] .= ' '.$part;

                continue;
            }

            $items[] = $part;
        }

        return $items === [] ? [$single] : $items;
    }

    /** True when the note has more than one thing in it. */
    public static function isList(?string $note): bool
    {
        return count(self::bullets($note)) > 1;
    }

    /** Drop a bullet or number the officer typed — the list supplies its own. */
    private static function strip(string $line): string
    {
        return trim(preg_replace('/^\s*(?:[-*•·]|\d+[.)])\s*/u', '', trim($line)) ?? '');
    }

    private static function endsWithAbbreviation(string $text): bool
    {
        if (! Str::endsWith($text, '.')) {
            return false;
        }

        $lastWord = Str::lower(trim((string) Str::afterLast(rtrim($text, '.'), ' ')));

        return in_array($lastWord, self::ABBREVIATIONS, true);
    }
}
