<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A photo or file sent in a job order conversation. */
class MessageFile extends Model
{
    protected $fillable = ['message_id', 'path', 'original_name', 'mime', 'size'];

    public function message()
    {
        return $this->belongsTo(Message::class);
    }

    /** Images render inline in the thread; anything else is a download link. */
    public function isImage(): bool
    {
        return str_starts_with((string) $this->mime, 'image/');
    }

    public function sizeForHumans(): string
    {
        $bytes = (int) $this->size;

        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 1).' MB';
        }

        return max(1, (int) round($bytes / 1024)).' KB';
    }
}
