<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TaskFile extends Model
{
    protected $fillable = [
        'task_id', 'path', 'external_path', 'original_name', 'label', 'mime', 'size', 'round', 'uploaded_by',
    ];

    /**
     * The location an artist recorded for their file.
     *
     * The file usually sits on the artist's OWN PC, whose address comes from
     * DHCP — and staff move between machines. So the address of the PC the
     * artist was signed in from is replaced by a marker when the path is
     * stored, and put back from that person's latest login whenever the path is
     * read. Everyone opening the path therefore gets the machine the artist is
     * on now, not the one they used the day they submitted.
     *
     * A path pointing at some other machine keeps its own address untouched.
     */
    public function setExternalPathAttribute(?string $value): void
    {
        $this->attributes['external_path'] = \App\Services\ServerIp::pack(
            $value,
            \App\Services\ServerIp::ipForUser(auth()->user())
        );
    }

    public function getExternalPathAttribute(?string $value): ?string
    {
        return \App\Services\ServerIp::unpack(
            $value,
            \App\Services\ServerIp::ipForUser($this->uploader)
        );
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /** A network-path reference rather than an uploaded/stored file. */
    public function isExternal(): bool
    {
        return filled($this->external_path);
    }

    /** True when the external path is a clickable web link (http/https). */
    public function isWebLink(): bool
    {
        return $this->isExternal() && preg_match('#^https?://#i', (string) $this->external_path) === 1;
    }

    /** Browser-previewable image — by mime for uploads, by extension for paths. */
    public function isImage(): bool
    {
        if ($this->isExternal()) {
            $ext = strtolower(pathinfo($this->external_path, PATHINFO_EXTENSION));

            return in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true);
        }

        return str_starts_with((string) $this->mime, 'image/');
    }

    public function isPdf(): bool
    {
        return $this->mime === 'application/pdf';
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
