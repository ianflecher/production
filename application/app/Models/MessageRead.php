<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** How far one person has read in one job order's conversation. */
class MessageRead extends Model
{
    protected $fillable = ['user_id', 'production_order_id', 'last_read_at', 'inquiry_id'];

    protected function casts(): array
    {
        return ['last_read_at' => 'datetime'];
    }
}
