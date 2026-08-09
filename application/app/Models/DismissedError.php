<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** An error somebody has looked at. See the migration for why it works this way. */
class DismissedError extends Model
{
    protected $fillable = ['fingerprint', 'dismissed_at', 'dismissed_by'];

    protected function casts(): array
    {
        return ['dismissed_at' => 'datetime'];
    }

    /** How a failure is identified across log lines: its level and its message. */
    public static function fingerprintFor(string $level, string $message): string
    {
        return hash('sha256', $level.'|'.$message);
    }
}
