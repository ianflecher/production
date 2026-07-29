<?php

namespace App\Services;

use App\Models\JobOrder;

/**
 * The physical work stations on the floor — printers, presses, cutting, pairing,
 * sewing and QC. Each maps to the pipeline task(s) that release work to it, so a
 * station can only be started on a job the leader has actually approved.
 */
class Stations
{
    /**
     * key => [label, group, departments it draws work from]
     *
     * @return array<string, array{label: string, group: string, departments: array<int, string>}>
     */
    public static function all(): array
    {
        $stations = [];

        // Supply chain: Raw materials & Sticker preparation
        $stations += [
            'raw_materials' => ['label' => 'Raw Materials', 'group' => 'Supply', 'departments' => ['Raw materials']],
            'sticker' => ['label' => 'Sticker Station', 'group' => 'Supply', 'departments' => ['Sticker']],
        ];

        // Printers come from the job order's printer list.
        foreach (JobOrder::PRINTERS as $key => $label) {
            $stations['printer_'.$key] = [
                'label' => $label,
                'group' => 'Printing',
                // The printers also run the rest of the batch once the client has
                // approved the first sample.
                'departments' => ['Printer', 'Mass production'],
            ];
        }

        // Decoration stations. Each press has 2 stations so two jobs can run at once.
        // (The fabric-merge press runs on these same press stations, by its type.)
        $stations['embroidery'] = ['label' => 'Embroidery', 'group' => 'Decoration', 'departments' => ['Embroidery']];
        foreach (['cap_press' => 'Cap press', 'heat_press' => 'Heat press', 'small_press' => 'Small press', 'roller_press' => 'Roller press'] as $key => $label) {
            for ($i = 1; $i <= 2; $i++) {
                $stations[$key.'_'.$i] = ['label' => "$label #$i", 'group' => 'Decoration', 'departments' => [$label]];
            }
        }

        // Cutting: 5 laser + 5 manual stations (station-based, no tasks)
        for ($i = 1; $i <= 5; $i++) {
            $stations['laser_cutting_'.$i] = [
                'label' => "Laser Cutting #$i",
                'group' => 'Cutting',
                'departments' => ['Laser cutting'],
            ];
        }
        for ($i = 1; $i <= 5; $i++) {
            $stations['manual_cutting_'.$i] = [
                'label' => "Manual Cutting #$i",
                'group' => 'Cutting',
                'departments' => ['Manual cutting'],
            ];
        }

        // Production line: 10 stations each (station-based, no tasks, no attendance checks)
        for ($i = 1; $i <= 10; $i++) {
            $stations['pairing_'.$i] = [
                'label' => "Pairing #$i",
                'group' => 'Production Line',
                'departments' => ['Pairing'],
            ];
        }
        for ($i = 1; $i <= 10; $i++) {
            $stations['sewing_'.$i] = [
                'label' => "Sewing #$i",
                'group' => 'Production Line',
                'departments' => ['Sewing'],
            ];
        }
        for ($i = 1; $i <= 10; $i++) {
            $stations['qc_'.$i] = [
                'label' => "Quality Control #$i",
                'group' => 'Production Line',
                'departments' => ['Quality control'],
            ];
        }

        // Mover: handles material movement between stations
        $stations['mover'] = [
            'label' => 'Mover',
            'group' => 'Production Line',
            // The mover carries work between machines but does NOT finish those
            // steps — cutting, pairing, sewing and QC are closed by the people on
            // those stations. The one step the mover owns is taking the finished
            // sample to the account officer.
            'departments' => ['Produce sample for client'],
        ];

        // The "Inventory" step is handled by the finished-products desk
        // (products page), not a machine station, so it has no station board tile.

        return $stations;
    }

    /**
     * Which stations a user may see/run, from their job role. Leaders and the
     * super admin see everything; each floor role sees only its own station(s).
     * An empty list means the station board is not for them.
     *
     * @return array<int, string> station keys
     */
    public static function forUser(\App\Models\User $user): array
    {
        if ($user->isLeader() || $user->isSuperAdmin()) {
            return self::keys();
        }

        $all = self::all();
        $printers = array_values(array_filter(self::keys(), fn ($k) => str_starts_with($k, 'printer_')));
        $lasers = array_values(array_filter(self::keys(), fn ($k) => str_starts_with($k, 'laser_cutting_')));
        $manuals = array_values(array_filter(self::keys(), fn ($k) => str_starts_with($k, 'manual_cutting_')));
        $cuttings = array_merge($lasers, $manuals);
        $pairings = array_values(array_filter(self::keys(), fn ($k) => str_starts_with($k, 'pairing_')));
        $sewings = array_values(array_filter(self::keys(), fn ($k) => str_starts_with($k, 'sewing_')));
        $qcs = array_values(array_filter(self::keys(), fn ($k) => str_starts_with($k, 'qc_')));
        $capPresses = array_values(array_filter(self::keys(), fn ($k) => str_starts_with($k, 'cap_press_')));
        $heatPresses = array_values(array_filter(self::keys(), fn ($k) => str_starts_with($k, 'heat_press_')));
        $smallPresses = array_values(array_filter(self::keys(), fn ($k) => str_starts_with($k, 'small_press_')));
        $rollerPresses = array_values(array_filter(self::keys(), fn ($k) => str_starts_with($k, 'roller_press_')));

        $map = [
            // Stickers are printed, so the printer operators run that station too.
            'printer' => array_merge($printers, ['sticker']),
            'supply_chain' => array_merge(['raw_materials', 'sticker'], $printers),
            'raw materials' => ['raw_materials'],
            'sticker' => ['sticker'],
            'embroidery' => ['embroidery'],
            'cap press' => $capPresses,
            'heat press' => $heatPresses,
            'small press' => $smallPresses,
            'roller press' => $rollerPresses,
            'cutting' => $cuttings,
            'laser cutting' => $lasers,
            'manual cutting' => $manuals,
            'pairing' => $pairings,
            'sewing' => $sewings,
            'quality control' => $qcs,
            'qc' => $qcs,
            'mover' => ['mover'],
            // The old broad "production" role covers the whole line.
            'production' => array_merge($cuttings, $pairings, $sewings, $qcs, ['mover']),
        ];

        return $map[strtolower(trim((string) $user->job_role))] ?? [];
    }

    public static function keys(): array
    {
        return array_keys(self::all());
    }

    /**
     * Which slice of the job package a station should see when it opens a job:
     *   printer    → job order + the print TIFF
     *   sticker    → job order + the sticker file
     *   embroidery → job order + the embroidery file
     *   production → job order + production details (press, cutting, sewing, QC…)
     */
    public static function scope(string $key): string
    {
        if (str_starts_with($key, 'printer_')) {
            return 'printer';
        }

        return match ($key) {
            'sticker' => 'sticker',
            'embroidery' => 'embroidery',
            default => 'production',
        };
    }

    public static function label(string $key): string
    {
        return self::all()[$key]['label'] ?? $key;
    }

    public static function departments(string $key): array
    {
        return self::all()[$key]['departments'] ?? [];
    }

    /** Stations grouped for display: Printing, Decoration, Production line. */
    public static function grouped(): array
    {
        $out = [];

        foreach (self::all() as $key => $s) {
            $out[$s['group']][$key] = $s;
        }

        return $out;
    }

    /** A step is released once it's out of "todo" and not finished/cancelled. */
    public const RELEASED = ['ready', 'in_progress', 'for_checking', 'revision_required'];
}
