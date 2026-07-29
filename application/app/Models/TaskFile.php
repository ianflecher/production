<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TaskFile extends Model
{
    protected $fillable = [
        'task_id', 'path', 'external_path', 'original_name', 'label', 'mime', 'size', 'round', 'uploaded_by',
    ];

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
