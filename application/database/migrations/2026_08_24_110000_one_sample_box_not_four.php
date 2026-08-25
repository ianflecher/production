<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The sample panel is one box now, not four quarter-size squares.
 *
 * Changing the default alone did nothing to the packs already on the system:
 * every save writes the box list back to the row, so a pack opened once before
 * today has the old four frozen in its own record and goes on showing them.
 *
 * So the rows are brought over too — but only where it costs nobody a picture.
 * A pack keeps its four boxes if it is holding an image in any of the three
 * being dropped; only the untouched panels, still the standard empty four,
 * collapse to the single box.
 */
return new class extends Migration
{
    public function up(): void
    {
        $old = ['front_flat', 'back_flat', 'flat_3', 'flat_4'];
        $dropping = ['back_flat', 'flat_3', 'flat_4'];

        DB::table('tech_packs')->select('id', 'image_boxes', 'image_uploads')->orderBy('id')
            ->chunk(200, function ($packs) use ($old, $dropping) {
                foreach ($packs as $pack) {
                    $boxes = json_decode((string) $pack->image_boxes, true);

                    if (! is_array($boxes) || array_values($boxes) !== $old) {
                        continue;
                    }

                    $uploads = json_decode((string) $pack->image_uploads, true) ?: [];

                    foreach ($dropping as $slot) {
                        if (filled($uploads[$slot] ?? null)) {
                            continue 2;
                        }
                    }

                    DB::table('tech_packs')->where('id', $pack->id)
                        ->update(['image_boxes' => json_encode(['front_flat'])]);
                }
            });
    }

    public function down(): void
    {
        // Nothing to put back: a panel that was collapsed was empty, and the
        // + button builds the boxes again whenever somebody wants them.
    }
};
