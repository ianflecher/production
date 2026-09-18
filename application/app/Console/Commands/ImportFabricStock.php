<?php

namespace App\Console\Commands;

use App\Models\InventoryItem;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Bring the fabric inventory in from the shop's spreadsheet.
 *
 * The raw materials supervisor's stock has only ever lived in
 * "Imprint Customs Material Inventory 2026.xlsx", on the FABRIC INVENTORY
 * sheet: a name, what is on the shelf, and what it costs per kilo. Not one of
 * the 1,670 rows already in inventory_items is fabric.
 *
 * Read from the sheet the shop actually keeps rather than retyped, because
 * 594 rows typed by hand is 594 chances to put a decimal in the wrong place.
 *
 * Idempotent: a fabric already here is updated, not duplicated, so the command
 * can be run again after the sheet is corrected.
 */
class ImportFabricStock extends Command
{
    protected $signature = 'fabric:import
        {file : the .xlsx to read}
        {--sheet=FABRIC INVENTORY : which sheet the stock is on}
        {--dry : read it and say what would happen, change nothing}';

    protected $description = "Import the raw materials supervisor's fabric stock from the shop's spreadsheet";

    public function handle(): int
    {
        $path = $this->argument('file');

        if (! is_file($path)) {
            $this->error("No such file: {$path}");

            return self::FAILURE;
        }

        $rows = $this->readSheet($path, $this->option('sheet'));

        if ($rows === null) {
            return self::FAILURE;
        }

        if (! $rows) {
            $this->error('That sheet has no fabric rows on it.');

            return self::FAILURE;
        }

        $this->info(count($rows).' fabric rows read from "'.$this->option('sheet').'".');

        if ($this->option('dry')) {
            $this->table(
                ['fabric', 'stock', 'beginning'],
                array_map(fn ($r) => [$r['name'], $r['quantity'], $r['beginning_stock']], array_slice($rows, 0, 15))
            );
            $this->line('…and '.max(0, count($rows) - 15).' more. Nothing was changed.');

            return self::SUCCESS;
        }

        $added = 0;
        $updated = 0;

        DB::transaction(function () use ($rows, &$added, &$updated) {
            foreach ($rows as $row) {
                $existing = InventoryItem::where('kind', InventoryItem::KIND_FABRIC)
                    ->where('name', $row['name'])
                    ->first();

                if ($existing) {
                    $existing->update($row);
                    $updated++;

                    continue;
                }

                InventoryItem::create($row + ['kind' => InventoryItem::KIND_FABRIC]);
                $added++;
            }
        });

        $this->info("Done — {$added} added, {$updated} updated.");
        $this->line('Ready-made stock is untouched: '.
            InventoryItem::where('kind', InventoryItem::KIND_READY_MADE)->count().' rows still on the desk.');

        return self::SUCCESS;
    }

    /**
     * The sheet, as rows this table can hold.
     *
     * Read with ZipArchive and SimpleXML rather than a spreadsheet library,
     * because an .xlsx IS a zip of XML and adding a dependency to read one
     * file once is a poor trade.
     *
     * @return array<int, array<string, mixed>>|null
     */
    private function readSheet(string $path, string $wanted): ?array
    {
        $zip = new \ZipArchive;

        if ($zip->open($path) !== true) {
            $this->error('That file will not open as a spreadsheet.');

            return null;
        }

        // Sheet names live apart from the sheets themselves, and the shared
        // string table holds most of the text.
        $workbook = simplexml_load_string((string) $zip->getFromName('xl/workbook.xml'));
        $rels = simplexml_load_string((string) $zip->getFromName('xl/_rels/workbook.xml.rels'));
        $strings = $this->sharedStrings($zip);

        $target = null;

        foreach ($workbook->sheets->sheet as $sheet) {
            if (trim((string) $sheet['name']) !== trim($wanted)) {
                continue;
            }

            $rid = (string) $sheet->attributes('r', true)->id;

            foreach ($rels->Relationship as $rel) {
                if ((string) $rel['Id'] === $rid) {
                    $target = 'xl/'.ltrim((string) $rel['Target'], '/');
                }
            }
        }

        if (! $target) {
            $this->error('No sheet called "'.$wanted.'" in that file.');
            $zip->close();

            return null;
        }

        $xml = simplexml_load_string((string) $zip->getFromName($target));
        $zip->close();

        $out = [];

        foreach ($xml->sheetData->row as $row) {
            // The sheet's own row number, not the loop's position: SimpleXML
            // does not hand out the index you would expect, and the header was
            // coming through as a fabric called "FABRIC".
            if ((int) $row['r'] <= 1) {
                continue;
            }

            $cells = [];

            foreach ($row->c as $c) {
                $ref = preg_replace('/\d+/', '', (string) $c['r']);
                $v = (string) $c->v;

                $cells[$ref] = ((string) $c['t'] === 's')
                    ? ($strings[(int) $v] ?? '')
                    : $v;
            }

            $name = trim((string) ($cells['A'] ?? ''));

            if ($name === '') {
                continue;
            }

            // The sheet is TWO things stacked: the stock list, and under it a
            // usage log with one line per job that drew on a fabric. They look
            // identical — same name, a figure in B — except that a usage line
            // carries the job it went to in column D, where the stock list
            // carries a beginning balance.
            //
            // Read straight through, the log overwrites the balances with the
            // last few kilos some job took: 594 rows collapse to 167 fabrics
            // holding 5,200kg instead of 164 holding 9,387kg. So the read stops
            // where the log starts.
            if (preg_match('/IC\s?20\d\d|SPONSOR|KOMBI/i', (string) ($cells['D'] ?? ''))) {
                break;
            }

            $out[] = [
                'name' => $name,
                // The shop's own grouping. One category for the lot: the sheet
                // does not split fabric further, and inventing a split here
                // would be the system telling the shop what it holds.
                'category' => 'FABRIC',
                'unit' => 'KG',
                'quantity' => $this->number($cells['B'] ?? null),
                'beginning_stock' => $this->number($cells['D'] ?? null),
            ];
        }

        // Three fabrics are listed twice in the stock block itself. Two lines
        // of one fabric are one shelf, so they are added together rather than
        // one of them quietly winning.
        $merged = [];

        foreach ($out as $row) {
            if (isset($merged[$row['name']])) {
                $merged[$row['name']]['quantity'] += $row['quantity'];
                $merged[$row['name']]['beginning_stock'] += $row['beginning_stock'];

                continue;
            }

            $merged[$row['name']] = $row;
        }

        return array_values($merged);
    }

    /** @return array<int, string> */
    private function sharedStrings(\ZipArchive $zip): array
    {
        $raw = $zip->getFromName('xl/sharedStrings.xml');

        if ($raw === false) {
            return [];
        }

        $xml = simplexml_load_string($raw);
        $out = [];

        foreach ($xml->si as $si) {
            // A string can be one run or several (mixed formatting), and the
            // pieces have to be put back together in order.
            $out[] = isset($si->t) && count($si->r) === 0
                ? (string) $si->t
                : implode('', array_map(fn ($r) => (string) $r->t, iterator_to_array($si->r ?? [])));
        }

        return $out;
    }

    private function number(?string $v): float
    {
        return is_numeric($v) ? round((float) $v, 2) : 0.0;
    }
}
