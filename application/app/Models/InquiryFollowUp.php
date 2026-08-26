<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One chase: who called, what was said, and when to call again. */
class InquiryFollowUp extends Model
{
    protected $fillable = ['inquiry_id', 'user_id', 'note', 'next_follow_up_on'];

    protected function casts(): array
    {
        return ['next_follow_up_on' => 'date'];
    }

    public function inquiry(): BelongsTo
    {
        return $this->belongsTo(Inquiry::class);
    }

    /** Named on the line, because a team leader chases members' inquiries. */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
