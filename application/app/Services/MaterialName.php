<?php

namespace App\Services;

/**
 * Reads the size and colour out of a material's description.
 *
 * The stock sheet keeps everything in one cell — "AIIZ SHIRT RN BLACK - XS" —
 * so the inventory's size and colour filters had nothing to work with. Rather
 * than ask anyone to re-key 1,571 rows, the two are read off the name.
 *
 * Nothing is guessed: a size only counts when it is one the shop actually uses,
 * and a colour only when it is a known colour word. Anything unrecognised is
 * left blank rather than filled in with something wrong.
 */
class MaterialName
{
    /** The sizes the shop sells, longest first so "2XL" beats "XL". */
    private const SIZES = [
        '6XL', '5XL', '4XL', '3XL', '2XL', '2XS', 'XL', 'XS', 'CS', 'FS', 'S', 'M', 'L',
    ];

    /**
     * Colour words seen in the shop's own stock sheet. Multi-word entries come
     * first so "HEATHER RED" is not read as plain "RED".
     */
    private const COLORS = [
        // two words first
        'HEATHER GRAY', 'HEATHER GREY', 'HEATHER RED', 'HEATHER BLUE', 'HEATHER NAVY',
        'OLD ROSE', 'ROYAL BLUE', 'NAVY BLUE', 'LIGHT BLUE', 'DARK BLUE', 'SKY BLUE',
        'LIGHT GRAY', 'DARK GRAY', 'LIGHT GREY', 'DARK GREY', 'CHARCOAL GRAY',
        'LIGHT GREEN', 'DARK GREEN', 'ARMY GREEN', 'FOREST GREEN', 'OLIVE GREEN',
        'HOT PINK', 'LIGHT PINK', 'DUSTY PINK', 'OFF WHITE', 'DIRTY WHITE',
        'ACID WASH', 'ACID BLUE', 'ACID BLACK',
        // single words
        'BLACK', 'WHITE', 'NAVY', 'ROYAL', 'MAROON', 'KHAKI', 'CREAM', 'BEIGE',
        'YELLOW', 'ORANGE', 'PURPLE', 'VIOLET', 'CAMOU', 'CHARCOAL', 'MUSTARD',
        'TURQUOISE', 'TEAL', 'BROWN', 'SILVER', 'GOLD', 'PEACH', 'MINT', 'LAVENDER',
        'BURGUNDY', 'OLIVE', 'GREEN', 'BLUE', 'GRAY', 'GREY', 'PINK', 'RED', 'ROSE',
        'SALMON', 'OAT',
        // Spellings the sheet actually uses, kept so those rows aren't missed.
        'LAVANDER', 'BURGANDY',
    ];

    /** The size at the end of a description, or null when there isn't one. */
    public static function size(string $name): ?string
    {
        // Sizes are written after the final dash: "… BLACK - 2XL".
        if (! preg_match('/\s-\s*([^-]+)$/', $name, $m)) {
            return null;
        }

        $tail = strtoupper(trim($m[1]));

        foreach (self::SIZES as $size) {
            // Whole token only, so "S" doesn't match inside "53X26 INCH".
            if ($tail === $size) {
                return $size;
            }
        }

        return null;
    }

    /** The colour mentioned in a description, or null when none is recognised. */
    public static function color(string $name): ?string
    {
        // Ignore the size portion so a colour is never taken from it.
        $body = strtoupper(preg_replace('/\s-\s*[^-]+$/', '', $name));

        foreach (self::COLORS as $color) {
            if (preg_match('/(?<![A-Z])'.preg_quote($color, '/').'(?![A-Z])/', $body)) {
                return $color;
            }
        }

        return null;
    }
}
