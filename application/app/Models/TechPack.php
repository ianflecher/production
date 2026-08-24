<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The tech pack: one sheet the shop prints and works a garment from.
 *
 * Its own table rather than more columns on job_orders, which was within two
 * thousand bytes of InnoDB's row limit — and because a tech pack is its own
 * document, not a wider job order.
 *
 * Only what the job order does not already carry lives here. Neck, cuff, print
 * label, packaging, sticker, fabric and print type stay on the job order, so
 * nothing has two homes and two answers.
 */
class TechPack extends Model
{
    protected $fillable = [
        'production_order_id',
        // Header
        'design_name', 'fitting', 'item_style', 'quality', 'print_tech', 'placing_title',
        // The three colourway swatches
        'color_1', 'color_2', 'color_3',
        // Exactly a front and a back, each with the size it prints at
        'front_print_placement', 'front_actual_size',
        'back_print_placement', 'back_actual_size',
        // Materials and components the job order does not carry
        'tshirt_color', 'stitch_thread', 'cutting_method', 'size_range', 'label_type', 'color_type',
        'zipper_type', 'lip_pocket_color',
        // The two woven-tag placements printed under the materials table
        'tag_1_details', 'tag_2_details',
        // Direct uploads for the eight picture boxes on the tech-pack sheet.
        'image_uploads', 'image_boxes', 'image_sizes', 'hidden_boxes', 'box_positions', 'callouts',
        'extra_notes',
        // Where the files are, and who drew it
        'folder_shot_path', 'folder_shot_name', 'file_location_notes',
        'additional_tech_notes', 'artist_name',
        'bottom_text', 'bottom_image_width', 'bottom_image_height',
        'bottom_text_width', 'bottom_text_height',
    ];

    protected $casts = [
        'image_uploads' => 'array',
        'image_boxes' => 'array',
        'image_sizes' => 'array',
        'hidden_boxes' => 'array',
        'box_positions' => 'array',
        'callouts' => 'array',
        'extra_notes' => 'array',
    ];

    public const IMAGE_SLOTS = [
        'front_mockup', 'back_mockup',
        'front_flat', 'back_flat', 'flat_3', 'flat_4',
        'back_artwork', 'front_artwork',
        'tag_1', 'tag_2',
        'file_location_image',
        'bottom_image',
    ];

    /**
     * The size an artist dragged one box to, as a share of the sheet's width.
     *
     * Empty means nobody has touched it and the stylesheet decides — which is
     * the right answer for most boxes on most jobs.
     *
     * @return array{w: float, h: float}|null
     */
    public function imageSize(string $slot): ?array
    {
        $size = ($this->image_sizes ?? [])[$slot] ?? null;

        if (! is_array($size) || ! isset($size['w'], $size['h'])) {
            return null;
        }

        return ['w' => (float) $size['w'], 'h' => (float) $size['h']];
    }

    /** That size as an inline style, or nothing at all. */
    public function imageSizeStyle(string $slot): string
    {
        $size = $this->imageSize($slot);

        return $size ? 'width:'.$size['w'].'cqw;height:'.$size['h'].'cqw;' : '';
    }

    /**
     * Text blocks the artist added, in the order they were added.
     *
     * The sheet names the things every job has. A job with something to say
     * that the sheet has no row for used to have nowhere to say it — so the
     * artist can add a block, write in it, and drag it where it belongs.
     *
     * @return array<int, string>
     */
    public function extraNotes(): array
    {
        return array_values((array) ($this->extra_notes ?? []));
    }

    /**
     * Where one box was dragged to, as an offset from where the sheet puts it.
     *
     * An offset rather than a position: the box keeps its place in the grid and
     * is nudged from there, so a pack nobody has moved prints exactly as it
     * always did, and a box that was moved stays put relative to its neighbours
     * when the sheet is drawn wider or narrower.
     *
     * @return array{x: float, y: float}|null
     */
    public function boxPosition(string $slot): ?array
    {
        $at = ($this->box_positions ?? [])[$slot] ?? null;

        if (! is_array($at) || ! isset($at['x'], $at['y'])) {
            return null;
        }

        return ['x' => (float) $at['x'], 'y' => (float) $at['y']];
    }

    /**
     * Where a moved thing is drawn, as an inline style.
     *
     * A PICTURE box is nudged from where the sheet puts it: it is the same size
     * on every copy, so the same nudge lands in the same place.
     *
     * A TEXT block is not. On the artist's copy it is a box you type in, with a
     * grip and a cross beside it; on everybody else's it is a line of printed
     * text. Different size, different place in the flow — so the same nudge put
     * it somewhere else, and the note that sat beside a picture on the artist's
     * sheet sat ON the picture on the floor's. A moved text block is pinned to
     * the sheet instead, at a point that means the same thing on every copy.
     */
    public function boxPositionStyle(string $slot): string
    {
        $at = $this->boxPosition($slot);

        if (! $at) {
            return '';
        }

        return $this->slotIsText($slot)
            ? 'position:absolute; left:'.$at['x'].'cqw; top:'.$at['y'].'cqw; margin:0;'
            : 'transform:translate('.$at['x'].'cqw,'.$at['y'].'cqw);';
    }

    /** Text blocks are the ones whose size differs between copies. */
    public function slotIsText(string $slot): bool
    {
        return str_starts_with($slot, 'text_') || str_starts_with($slot, 'note_');
    }

    /**
     * Boxes this pack does without.
     *
     * The sample panel keeps a list of the boxes it HAS; every other box is
     * part of the sheet, so the only way to take one off is to name it here. A
     * job with one woven tag should print one tag, not one tag and an empty
     * square waiting for a picture that is never coming.
     */
    public function hiddenBoxes(): array
    {
        return array_values(array_filter((array) $this->hidden_boxes));
    }

    public function boxIsHidden(string $slot): bool
    {
        return in_array($slot, $this->hiddenBoxes(), true);
    }

    /** The sample panel as it stands when nobody has added or removed a box. */
    public const DEFAULT_SAMPLE_BOXES = ['front_flat', 'back_flat', 'flat_3', 'flat_4'];

    /** Room to grow. Boxes past the standard four are named in this range. */
    public const SPARE_SAMPLE_SLOTS = [
        'flat_3', 'flat_4', 'flat_5', 'flat_6', 'flat_7', 'flat_8',
        'flat_9', 'flat_10', 'flat_11', 'flat_12',
    ];

    /**
     * The leader lines: where each one starts and where it points.
     *
     * Both ends are the artist's to place — the end that leaves the box as much
     * as the end that lands on the garment, because which side a line should
     * leave from depends on where the box has been dragged to.
     *
     * Points on the SHEET, as a share of its width, so a line lands in the same
     * place whatever size the sheet is drawn at. A line saved before both ends
     * were movable has only its far end; the near one is worked out from the
     * box, as it was then.
     *
     * @return array<string, array{from: ?array{x: float, y: float}, to: array{x: float, y: float}}>
     */
    public function callouts(): array
    {
        $out = [];

        foreach ((array) ($this->callouts ?? []) as $slot => $at) {
            if (! is_array($at)) {
                continue;
            }

            $to = isset($at['tx'], $at['ty'])
                ? ['x' => (float) $at['tx'], 'y' => (float) $at['ty']]
                : (isset($at['x'], $at['y']) ? ['x' => (float) $at['x'], 'y' => (float) $at['y']] : null);

            if (! $to) {
                continue;
            }

            $out[$slot] = [
                'from' => isset($at['fx'], $at['fy'])
                    ? ['x' => (float) $at['fx'], 'y' => (float) $at['fy']]
                    : null,
                'to' => $to,
            ];
        }

        return $out;
    }

    /** Printed text blocks that can be taken off the sheet like a picture box. */
    public const TEXT_SLOTS = ['text_tag_1', 'text_tag_2', 'text_banner'];

    /** Anything the × can remove: pictures and printed text alike. */
    public static function removableSlots(): array
    {
        return array_merge(self::imageSlots(), self::TEXT_SLOTS);
    }

    /** Every slot that may hold a picture, standard boxes and spares alike. */
    public static function imageSlots(): array
    {
        return array_values(array_unique(array_merge(self::IMAGE_SLOTS, self::SPARE_SAMPLE_SLOTS)));
    }

    /**
     * The sample panel's boxes, in order. An artist who removed every one gets
     * an empty panel — that is an answer too, and adding one back is a click.
     */
    public function sampleBoxes(): array
    {
        $boxes = $this->image_boxes;

        return is_array($boxes) ? array_values($boxes) : self::DEFAULT_SAMPLE_BOXES;
    }

    /** The next free box name, or null when the panel is full. */
    public function nextSampleSlot(): ?string
    {
        $taken = $this->sampleBoxes();

        foreach (array_merge(self::DEFAULT_SAMPLE_BOXES, self::SPARE_SAMPLE_SLOTS) as $slot) {
            if (! in_array($slot, $taken, true)) {
                return $slot;
            }
        }

        return null;
    }

    /**
     * Where the print-ready files were saved, written the way the export step
     * used to write it: the machine's address is swapped for a marker on the
     * way in and put back on the way out, so the path follows the artist to
     * whatever PC they are sitting at instead of freezing to one machine.
     */
    public function setFileLocationNotesAttribute(?string $value): void
    {
        $this->attributes['file_location_notes'] = \App\Services\ServerIp::pack(
            $value,
            \App\Services\ServerIp::ipForUser(auth()->user())
        );
    }

    public function getFileLocationNotesAttribute(?string $value): ?string
    {
        return \App\Services\ServerIp::unpack(
            $value,
            \App\Services\ServerIp::ipForUser(auth()->user())
        );
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(ProductionOrder::class, 'production_order_id');
    }

    /** The colourway swatches that were actually named, in order. */
    public function colorways(): array
    {
        return array_values(array_filter([
            $this->color_1, $this->color_2, $this->color_3,
        ], fn ($c) => filled($c)));
    }
}
