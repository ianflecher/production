<?php

namespace App\Console\Commands;

use App\Models\InventoryItem;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Worksheet\MemoryDrawing;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Take the mock-up photos out of the stock sheet and put them on the materials.
 *
 * The photos in the MOCK UP column are not cell values — they float above the
 * grid — so a CSV export drops them silently, which is why the column comes out
 * empty. They survive in the .xlsx, which is a zip with the pictures inside it
 * and a note of which cell each one is pinned to. That pin is the only thing
 * connecting a picture to a material, so it is what this reads.
 *
 * One photo covers a whole block: the sheet has one mock-up for AIIZ SHIRT RN
 * BLACK and then a row per size under it. --spread is what puts that one photo
 * on all fifteen of them.
 *
 *   php artisan inventory:photos "C:\path\STOCK RAW MATERIAL.xlsx" --spread --dry-run
 */
class ImportMaterialPhotos extends Command
{
    protected $signature = 'inventory:photos
        {file : the .xlsx stock sheet (not the CSV — a CSV has no pictures in it)}
        {--spread : put each photo on every size in its block, not only the pinned row}
        {--sheet= : which tab to read (default: the first one)}
        {--force : replace photos on materials that already have one}
        {--dry-run : say what would happen and write nothing}';

    protected $description = 'Attach the mock-up photos from a stock sheet to the raw materials';

    public function handle(): int
    {
        $path = $this->argument('file');

        if (! is_file($path)) {
            $this->error("No such file: {$path}");

            return self::FAILURE;
        }

        if (str_ends_with(strtolower($path), '.csv')) {
            $this->error('That is the CSV. A CSV is plain text and cannot hold a picture — the MOCK UP');
            $this->error('column comes out empty. Export the sheet again as Excel (.xlsx) and use that.');

            return self::FAILURE;
        }

        try {
            // NOT read-data-only: that is exactly the switch that skips the
            // pictures, and the pictures are the whole point here.
            $reader = IOFactory::createReaderForFile($path);

            // The stock book has a dozen tabs and half a gigabyte of pictures.
            // Only one tab is the stock sheet, and loading the rest is how this
            // runs out of memory on a file this size.
            $names = $reader->listWorksheetNames($path);
            $wanted = $this->option('sheet') ?: ($names[0] ?? null);

            if ($wanted !== null && ! in_array($wanted, $names, true)) {
                $this->error('No tab called "'.$wanted.'". This book has: '.implode(', ', $names));

                return self::FAILURE;
            }

            $this->line('Reading "'.$wanted.'" — this is a large book, give it a minute.');
            $reader->setLoadSheetsOnly($wanted);

            $sheet = $reader->load($path)->getActiveSheet();
        } catch (\Throwable $e) {
            $this->error('That workbook could not be opened: '.$e->getMessage());
            $this->line('If it was copied while Excel had it open, it is probably half-written —');
            $this->line('open it and use File → Save As to write a fresh copy.');

            return self::FAILURE;
        }

        $descCol = $this->descriptionColumn($sheet);

        if ($descCol === null) {
            $this->error('No DESCRIPTION column found in the first rows — is this the stock sheet?');

            return self::FAILURE;
        }

        $drawings = $sheet->getDrawingCollection();
        $this->info(count($drawings).' picture(s) in the sheet.');

        if (count($drawings) === 0) {
            $this->warn('None to import. If the sheet shows pictures, they may be on another tab.');

            return self::SUCCESS;
        }

        $dry = (bool) $this->option('dry-run');
        $attached = 0;
        $skipped = 0;
        $unmatched = [];

        foreach ($drawings as $drawing) {
            $row = (int) preg_replace('/\D/', '', $drawing->getCoordinates());
            $name = $this->nameNear($sheet, $descCol, $row);

            if ($name === null) {
                $unmatched[] = $drawing->getCoordinates();

                continue;
            }

            $targets = $this->targets($name);

            if ($targets->isEmpty()) {
                $unmatched[] = $drawing->getCoordinates().' ("'.$name.'" — no such material)';

                continue;
            }

            $image = $this->imageBytes($drawing);

            if ($image === null) {
                $unmatched[] = $drawing->getCoordinates().' (picture could not be read)';

                continue;
            }

            [$contents, $extension] = $image;
            $stored = null;

            foreach ($targets as $item) {
                if ($item->photo && ! $this->option('force')) {
                    $skipped++;

                    continue;
                }

                if ($dry) {
                    $attached++;

                    continue;
                }

                // Written once and shared by the block: the same photo on
                // fifteen sizes is one picture, not fifteen copies of it.
                $stored ??= tap(
                    'inventory-photos/'.\Illuminate\Support\Str::uuid().'.'.$extension,
                    fn ($p) => Storage::disk('public')->put($p, $contents)
                );

                $item->update(['photo' => $stored]);
                $attached++;
            }
        }

        $this->newLine();
        $this->info(($dry ? 'Would attach ' : 'Attached ').$attached.' photo(s) to materials.');

        if ($skipped > 0) {
            $this->line($skipped.' already had a photo and were left alone (--force to replace).');
        }

        foreach ($unmatched as $where) {
            $this->warn('  no material for the picture pinned at '.$where);
        }

        if ($dry) {
            $this->newLine();
            $this->comment('Dry run — nothing was written. Drop --dry-run to do it for real.');
        }

        return self::SUCCESS;
    }

    /** Which column the material names are in. */
    private function descriptionColumn(Worksheet $sheet): ?int
    {
        foreach (range(1, 6) as $row) {
            foreach (range(1, 15) as $col) {
                if (strtoupper(trim((string) $sheet->getCell([$col, $row])->getValue())) === 'DESCRIPTION') {
                    return $col;
                }
            }
        }

        return null;
    }

    /**
     * The material a picture belongs to.
     *
     * A picture is pinned wherever it was dropped, which is rarely dead on the
     * row it describes — it usually sits over the first row or two of its
     * block. So the pinned row is tried first, then a few rows below it.
     */
    private function nameNear(Worksheet $sheet, int $col, int $row): ?string
    {
        foreach (range(0, 4) as $offset) {
            $value = trim((string) $sheet->getCell([$col, $row + $offset])->getValue());

            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    /** The materials this photo is for: the pinned one, or its whole block. */
    private function targets(string $name)
    {
        $exact = InventoryItem::where('name', $name)->get();

        if (! $this->option('spread')) {
            return $exact;
        }

        // "AIIZ SHIRT RN BLACK - XS" and its fourteen siblings are one garment
        // photographed once. The size is the last " - " piece of the name.
        $base = preg_replace('/\s*-\s*[^-]+$/', '', $name);

        if ($base === '' || $base === $name) {
            return $exact;
        }

        return InventoryItem::where('name', 'like', $base.'%')->get();
    }

    /**
     * The picture itself, as bytes plus a file extension.
     *
     * @return array{0: string, 1: string}|null
     */
    private function imageBytes($drawing): ?array
    {
        try {
            if ($drawing instanceof MemoryDrawing) {
                ob_start();
                imagepng($drawing->getImageResource());

                return [(string) ob_get_clean(), 'png'];
            }

            if ($drawing instanceof Drawing) {
                // Loaded from the workbook, the path is a zip:// pointer into
                // the .xlsx rather than a file on disk.
                $contents = file_get_contents($drawing->getPath());

                if ($contents === false) {
                    return null;
                }

                $extension = strtolower($drawing->getExtension() ?: 'png');

                return [$contents, in_array($extension, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true) ? $extension : 'png'];
            }
        } catch (\Throwable) {
            return null;
        }

        return null;
    }
}
