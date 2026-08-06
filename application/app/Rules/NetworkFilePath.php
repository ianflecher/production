<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A path someone else on the office network can actually open, e.g.
 *
 *     \\192.168.150.233\Designs\IC2026-00003.tif
 *
 * The box is pre-filled with the artist's own PC address, which used to be
 * enough to submit on its own: the address alone passed, the step completed,
 * and production was handed somewhere with no file in it. What it needs is the
 * address AND the folder AND the file after it.
 *
 * Each way of getting it wrong is named, because "invalid path" tells the
 * person at the machine nothing about what to fix.
 */
class NetworkFilePath implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $path = trim((string) $value);

        if ($path === '') {
            $fail('Enter the file path.');

            return;
        }

        if (str_contains($path, '/')) {
            $fail('Use back-slashes \\ — not forward slashes /.');

            return;
        }

        if (! str_starts_with($path, '\\\\')) {
            $fail('Start the path with \\\\ and the PC address, e.g. \\\\192.168.1.1\\Designs\\file.tif');

            return;
        }

        // \\host\folder\file — the PC, the shared folder, then the file itself.
        // A folder shared on its own is still not a file production can open.
        $segments = array_values(array_filter(explode('\\', substr($path, 2)), fn ($s) => $s !== ''));

        if (count($segments) < 3) {
            $fail('Add the folder and file name after the address — e.g. \\\\192.168.1.1\\Designs\\file.tif');

            return;
        }

        if (str_ends_with($path, '\\')) {
            $fail('The path ends with \\ — finish it with the file name.');
        }
    }
}
