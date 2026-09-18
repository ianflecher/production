<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JobOrderFile extends Model
{
    /**
     * Put here by the officer through "Send files to the artist", rather than
     * uploaded as part of the brief or the tech pack.
     *
     * Its own kind because it is its own act: whatever else is happening to
     * the order, a file sent this way is FOR the artist, and the artist has to
     * be able to see it without waiting for a stage that comes later.
     */
    public const KIND_SENT = 'sent';

    protected $fillable = [
        'job_order_id', 'path', 'external_path', 'original_name', 'kind', 'note', 'mime', 'size', 'uploaded_by',
    ];

    /**
     * A reference that lives somewhere else — a Drive folder, a Facebook
     * post, a board of pegs — rather than a file uploaded here.
     *
     * Named the way task_files names the same idea, so the two read alike.
     */
    public function isExternal(): bool
    {
        return filled($this->external_path);
    }

    /** True when that somewhere else is a clickable web address. */
    public function isWebLink(): bool
    {
        return $this->isExternal() && preg_match('#^https?://#i', (string) $this->external_path) === 1;
    }

    public function jobOrder(): BelongsTo
    {
        return $this->belongsTo(JobOrder::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function isImage(): bool
    {
        // A link has no mime and is never drawn as a picture, whatever it
        // points at — the page has not fetched it and will not guess.
        return ! $this->isExternal() && str_starts_with((string) $this->mime, 'image/');
    }

    public function sizeForHumans(): string
    {
        $bytes = (int) $this->size;

        return match (true) {
            $bytes >= 1048576 => round($bytes / 1048576, 1).' MB',
            $bytes >= 1024 => round($bytes / 1024).' KB',
            default => $bytes.' B',
        };
    }
}
