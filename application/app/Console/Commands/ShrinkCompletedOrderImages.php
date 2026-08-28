<?php

namespace App\Console\Commands;

use App\Models\JobOrderFile;
use App\Models\Payment;
use App\Models\ProductionOrder;
use App\Models\TaskFile;
use App\Models\TechPack;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Make the pictures of finished jobs smaller, keeping the originals.
 *
 * The database is under three megabytes; the uploads are nearly four hundred.
 * Deleting finished orders would free almost nothing and cost the shop its
 * ledger — the weight is in the images, and a payment slip photographed at
 * 5000 x 5000 and stored as a PNG is twenty-five megabytes to read a reference
 * number off.
 *
 * So the pictures are re-encoded, not the records deleted. A job that finished
 * two months ago is delivered and paid; nobody is going to print from its
 * reference photos again, and 2000px is still sharper than any screen it will
 * be looked at on.
 *
 * The original goes to the archive before anything is touched — beside the
 * nightly backups in OneDrive, one folder per order, under its own order
 * number, with the name it was uploaded with. Not a database, not a zip: a
 * folder somebody can open when a client argues about a payment.
 *
 * Nothing happens without --apply. On its own this counts what it would save.
 */
class ShrinkCompletedOrderImages extends Command
{
    protected $signature = 'images:shrink-completed
        {--days=60 : Only orders finished at least this many days ago}
        {--max=2000 : Longest side, in pixels, after shrinking}
        {--quality=82 : JPEG quality}
        {--min-kb=400 : Leave anything already smaller than this alone}
        {--archive= : Where the originals go (default: OneDrive\\ImprintArchive)}
        {--apply : Actually do it. Without this the command only reports}';

    protected $description = 'Shrink the images of long-finished orders, keeping every original in the archive';

    private int $seen = 0;

    private int $done = 0;

    private int $before = 0;

    private int $after = 0;

    private array $problems = [];

    public function handle(): int
    {
        if (! function_exists('imagecreatetruecolor')) {
            $this->error('PHP has no GD extension, so it cannot read images. Nothing done.');

            return self::FAILURE;
        }

        $days = (int) $this->option('days');
        $apply = (bool) $this->option('apply');
        $archive = $this->archiveRoot();

        $cutoff = now()->subDays($days);

        $orders = ProductionOrder::where('status', 'complete')
            ->where(fn ($q) => $q->where('completed_at', '<=', $cutoff)
                ->orWhere(fn ($w) => $w->whereNull('completed_at')->where('updated_at', '<=', $cutoff)))
            ->with(['payments', 'techPack', 'jobOrder.files', 'tasks.files'])
            ->get();

        $this->info(sprintf(
            '%s order(s) finished on or before %s.%s',
            $orders->count(),
            $cutoff->format('M j, Y'),
            $apply ? '' : '  (reporting only — pass --apply to do it)'
        ));

        if ($orders->isEmpty()) {
            return self::SUCCESS;
        }

        $this->line('Originals go to: '.$archive);
        $this->newLine();

        foreach ($orders as $order) {
            foreach ($this->filesOf($order) as $file) {
                $this->handleOne($order, $file, $apply, $archive);
            }
        }

        $this->report($apply);

        return self::SUCCESS;
    }

    /** Where the originals are kept. Beside the nightly backups by default. */
    private function archiveRoot(): string
    {
        if ($given = $this->option('archive')) {
            return rtrim($given, '\\/');
        }

        $home = getenv('USERPROFILE') ?: getenv('HOME') ?: storage_path();

        return $home.DIRECTORY_SEPARATOR.'OneDrive'.DIRECTORY_SEPARATOR.'ImprintArchive';
    }

    /**
     * Every picture this order owns, wherever it is recorded.
     *
     * Each one carries how to write its new location back, because converting
     * a PNG to a JPEG changes the file name and a path nobody updated is a
     * broken picture on the sheet.
     *
     * @return array<int, array{path: string, name: string, save: callable}>
     */
    private function filesOf(ProductionOrder $order): array
    {
        $out = [];

        foreach ($order->payments as $payment) {
            if ($payment->proof_path) {
                $out[] = [
                    'path' => $payment->proof_path,
                    'name' => $payment->proof_name ?: basename($payment->proof_path),
                    'save' => fn (string $p) => Payment::whereKey($payment->id)->update(['proof_path' => $p]),
                ];
            }
        }

        if ($pack = $order->techPack) {
            if ($pack->folder_shot_path) {
                $out[] = [
                    'path' => $pack->folder_shot_path,
                    'name' => $pack->folder_shot_name ?: basename($pack->folder_shot_path),
                    'save' => fn (string $p) => TechPack::whereKey($pack->id)->update(['folder_shot_path' => $p]),
                ];
            }

            foreach ((array) $pack->image_uploads as $slot => $upload) {
                if (! filled($upload['path'] ?? null)) {
                    continue;
                }

                $out[] = [
                    'path' => $upload['path'],
                    'name' => $upload['name'] ?? basename($upload['path']),
                    'save' => function (string $p) use ($pack, $slot) {
                        $all = (array) $pack->fresh()->image_uploads;
                        $all[$slot]['path'] = $p;
                        TechPack::whereKey($pack->id)->update(['image_uploads' => json_encode($all)]);
                    },
                ];
            }
        }

        foreach ($order->jobOrder?->files ?? [] as $ref) {
            $out[] = [
                'path' => $ref->path,
                'name' => $ref->original_name ?: basename($ref->path),
                'save' => fn (string $p) => JobOrderFile::whereKey($ref->id)->update(['path' => $p]),
            ];
        }

        foreach ($order->tasks as $task) {
            foreach ($task->files as $tf) {
                $out[] = [
                    'path' => $tf->path,
                    'name' => $tf->original_name ?: basename($tf->path),
                    'save' => fn (string $p) => TaskFile::whereKey($tf->id)->update(['path' => $p]),
                ];
            }
        }

        return $out;
    }

    /** @param array{path: string, name: string, save: callable} $file */
    private function handleOne(ProductionOrder $order, array $file, bool $apply, string $archive): void
    {
        $full = storage_path('app'.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $file['path']));

        if (! is_file($full)) {
            return;     // already gone, or held somewhere else
        }

        $size = filesize($full);
        $this->seen++;

        // Small already, or not a picture: nothing worth doing, and re-encoding
        // a small image usually makes it bigger.
        if ($size < ((int) $this->option('min-kb') * 1024) || ! @getimagesize($full)) {
            return;
        }

        $shrunk = $this->reencode($full);

        if (! $shrunk) {
            $this->problems[] = $file['path'].' — could not be read as an image';

            return;
        }

        // A picture that would grow is left exactly as it is.
        if ($shrunk['size'] >= $size) {
            @unlink($shrunk['tmp']);

            return;
        }

        $this->before += $size;
        $this->after += $shrunk['size'];
        $this->done++;

        if (! $apply) {
            @unlink($shrunk['tmp']);

            return;
        }

        // The original goes to the archive FIRST. If that fails, the picture is
        // left alone: the one thing this must never do is destroy the only copy.
        $kept = $archive.DIRECTORY_SEPARATOR.$order->order_number;

        if (! File::isDirectory($kept) && ! File::makeDirectory($kept, 0775, true)) {
            $this->problems[] = $file['path'].' — could not make its archive folder';
            @unlink($shrunk['tmp']);

            return;
        }

        $keptAs = $kept.DIRECTORY_SEPARATOR.basename($full);

        if (! @copy($full, $keptAs) || filesize($keptAs) !== $size) {
            $this->problems[] = $file['path'].' — the original would not archive';
            @unlink($shrunk['tmp']);

            return;
        }

        // A JPEG under a .png name is a file nothing can open, so the name
        // changes with the format and the record is told where it went.
        $newPath = preg_replace('/\.[^.\/\\\\]+$/', '', $file['path']).'.jpg';
        $newFull = storage_path('app'.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $newPath));

        if (! @rename($shrunk['tmp'], $newFull)) {
            $this->problems[] = $file['path'].' — the smaller copy would not save';
            @unlink($shrunk['tmp']);

            return;
        }

        ($file['save'])($newPath);

        if ($newFull !== $full) {
            @unlink($full);
        }
    }

    /**
     * @return array{tmp: string, size: int}|null
     */
    private function reencode(string $full): ?array
    {
        $info = @getimagesize($full);

        if (! $info) {
            return null;
        }

        $img = match ($info[2]) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($full),
            IMAGETYPE_PNG => @imagecreatefrompng($full),
            IMAGETYPE_WEBP => @imagecreatefromwebp($full),
            IMAGETYPE_GIF => @imagecreatefromgif($full),
            default => null,
        };

        if (! $img) {
            return null;
        }

        [$w, $h] = $info;
        $max = (int) $this->option('max');
        $scale = min(1, $max / max($w, $h));
        $nw = max(1, (int) round($w * $scale));
        $nh = max(1, (int) round($h * $scale));

        $out = imagecreatetruecolor($nw, $nh);
        // White behind it: a transparent PNG flattened onto black is a photo
        // of nothing.
        imagefill($out, 0, 0, imagecolorallocate($out, 255, 255, 255));
        imagecopyresampled($out, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);

        $tmp = tempnam(sys_get_temp_dir(), 'shrink').'.jpg';
        $ok = imagejpeg($out, $tmp, (int) $this->option('quality'));

        imagedestroy($img);
        imagedestroy($out);

        if (! $ok || ! is_file($tmp) || ! @getimagesize($tmp)) {
            @unlink($tmp);

            return null;
        }

        return ['tmp' => $tmp, 'size' => filesize($tmp)];
    }

    private function report(bool $apply): void
    {
        $mb = fn (int $b) => number_format($b / 1048576, 1).' MB';

        $this->newLine();
        $this->line('Pictures looked at : '.$this->seen);
        $this->line(($apply ? 'Shrunk             : ' : 'Would shrink       : ').$this->done);

        if ($this->done) {
            $this->line('Before             : '.$mb($this->before));
            $this->line('After              : '.$mb($this->after));
            $this->info(($apply ? 'Freed              : ' : 'Would free         : ')
                .$mb($this->before - $this->after)
                .sprintf('  (%.0f%%)', 100 - ($this->after / max(1, $this->before) * 100)));
        }

        foreach ($this->problems as $problem) {
            $this->warn('left alone: '.$problem);
        }

        if (! $apply && $this->done) {
            $this->newLine();
            $this->comment('Nothing has been changed. Add --apply to do it.');
        }
    }
}
