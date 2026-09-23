<?php

namespace App\Console\Commands;

use App\Models\InventoryItem;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Bring a ready-made stock count in from a CSV of the shop's own sheet.
 *
 * The caps and the sewing/QC materials live in Google Sheets and reach us as
 * printed PDFs — a photo in every row, which is why the quantities cannot be
 * read off the text layer and are pulled out of the ruled table instead. That
 * part is done before this: what arrives here is three columns, name, category
 * and quantity, and a person has looked at them.
 *
 * Idempotent: a material already on the shelf has its count set to what the
 * sheet says, rather than being added a second time. The sheet is a count, not
 * a delivery.
 *
 * Fabric is never touched. That shelf is the supervisor's and has its own
 * command — see ImportFabricStock.
 */
class ImportReadyMadeStock extends Command
{
    protected $signature = 'stock:import
        {file : the .csv to read - name, category, quantity}
        {--unit=pcs : what the shop counts these in}
        {--force-photos : replace the picture on a material that already has one}
        {--dry : read it and say what would happen, change nothing}';

    protected $description = "Import a ready-made stock count from a CSV of the shop's sheet";

    public function handle(): int
    {
        $path = $this->argument('file');

        if (! is_file($path)) {
            $this->error("No such file: {$path}");

            return self::FAILURE;
        }

        $rows = $this->read($path);

        if ($rows === null) {
            return self::FAILURE;
        }

        if (! $rows) {
            $this->error('That file has no stock rows on it.');

            return self::FAILURE;
        }

        $this->info(count($rows).' rows read, '
            .number_format(array_sum(array_column($rows, 'quantity'))).' '.$this->option('unit').' in total.');

        // What is already here under the same name, so the count can say which
        // of these the shop has seen before.
        $existing = InventoryItem::query()
            ->where('kind', InventoryItem::KIND_READY_MADE)
            ->whereIn('name', array_column($rows, 'name'))
            ->pluck('quantity', 'name');

        $this->line('  '.$existing->count().' of them are already on the ready-made shelf and will be recounted.');

        if ($this->option('dry')) {
            $this->table(
                ['material', 'category', 'count', 'was'],
                array_map(fn ($r) => [
                    $r['name'], $r['category'], $r['quantity'],
                    $existing[$r['name']] ?? '—',
                ], array_slice($rows, 0, 15))
            );
            $this->line('…and '.max(0, count($rows) - 15).' more. Nothing was changed.');

            return self::SUCCESS;
        }

        $added = 0;
        $recounted = 0;
        $pictured = 0;
        $onTheOtherShelf = [];

        DB::transaction(function () use ($rows, &$added, &$recounted, &$pictured, &$onTheOtherShelf) {
            foreach ($rows as $row) {
                // By name alone: a material name is unique across BOTH shelves,
                // so asking only about this one would find nothing and then
                // fail on the way in.
                $item = InventoryItem::where('name', $row['name'])->first();

                if ($item && $item->kind !== InventoryItem::KIND_READY_MADE) {
                    // The supervisor's fabric under the same name. Moving it
                    // onto this shelf would take it off hers, so it is left
                    // alone and said out loud.
                    $onTheOtherShelf[] = $row['name'];

                    continue;
                }

                $photo = $row['photo_file'] ?? null;
                unset($row['photo_file']);

                if ($item) {
                    $item->update($row);
                    $recounted++;
                } else {
                    $item = InventoryItem::create($row + ['kind' => InventoryItem::KIND_READY_MADE]);
                    $added++;
                }

                if ($this->attachPhoto($item, $photo)) {
                    $pictured++;
                }
            }
        });

        $this->info("Done — {$added} added, {$recounted} recounted, {$pictured} given a picture.");

        if ($onTheOtherShelf) {
            $this->warn(count($onTheOtherShelf).' left alone — already on the fabric shelf under the same name:');

            foreach (array_slice($onTheOtherShelf, 0, 10) as $name) {
                $this->line('   '.$name);
            }
        }
        $this->line('Fabric is untouched: '.
            InventoryItem::where('kind', InventoryItem::KIND_FABRIC)->count().' rows still on the supervisor\'s shelf.');

        return self::SUCCESS;
    }

    /**
     * Put the sheet's picture of a material on the material.
     *
     * The shop's sheet has a photograph of every cap and every zip, and the
     * inventory page has somewhere to show it. A keeper reading "ORDINARY
     * SNAPBACK CAP ALL BLACK/GRAY BUTTON" off a screen is being asked to tell
     * it apart from eleven near-identical names; the picture answers that in
     * one look.
     *
     * Left alone if the material already has one, unless asked otherwise: a
     * photo somebody uploaded by hand is worth more than one off a printout.
     */
    private function attachPhoto(InventoryItem $item, ?string $file): bool
    {
        if (blank($file) || ! is_file($file)) {
            return false;
        }

        if ($item->photo && ! $this->option('force-photos')) {
            return false;
        }

        $path = 'inventory-photos/'.Str::uuid().'.'.(pathinfo($file, PATHINFO_EXTENSION) ?: 'jpg');

        Storage::disk('public')->put($path, file_get_contents($file));

        $item->update(['photo' => $path]);

        return true;
    }

    /**
     * The file, as rows this table can hold.
     *
     * @return array<int, array<string, mixed>>|null
     */
    private function read(string $path): ?array
    {
        $handle = fopen($path, 'r');

        if (! $handle) {
            $this->error('That file will not open.');

            return null;
        }

        $header = fgetcsv($handle);

        if (! $header) {
            fclose($handle);
            $this->error('That file is empty.');

            return null;
        }

        $columns = array_flip(array_map(fn ($h) => strtolower(trim((string) $h)), $header));

        foreach (['name', 'quantity'] as $needed) {
            if (! isset($columns[$needed])) {
                fclose($handle);
                $this->error('That file has no "'.$needed.'" column.');

                return null;
            }
        }

        $out = [];
        $unit = (string) $this->option('unit');

        while (($line = fgetcsv($handle)) !== false) {
            $name = trim((string) ($line[$columns['name']] ?? ''));
            $quantity = trim((string) ($line[$columns['quantity']] ?? ''));

            // A row with no name is not a material, and one with no count is a
            // question rather than an answer. Neither belongs on a shelf.
            if ($name === '' || ! is_numeric($quantity)) {
                continue;
            }

            $out[] = [
                'name' => $name,
                'category' => trim((string) ($line[$columns['category']] ?? '')) ?: 'OTHER',
                'unit' => $unit,
                'quantity' => (float) $quantity,
                // Where the picture of it is, if the sheet had one. Taken off
                // the row rather than stored, so a sheet without pictures reads
                // exactly the same.
                'photo_file' => isset($columns['photo_file'])
                    ? trim((string) ($line[$columns['photo_file']] ?? ''))
                    : null,
            ];
        }

        fclose($handle);

        return $out;
    }
}
