<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Client extends Model
{
    protected $fillable = [
        'name', 'last_name', 'contact_number', 'company',
        'office_address', 'delivery_address', 'tin',
        'created_by',
    ];

    /** Store the client name in Title Case so it reads the same everywhere. */
    protected function name(): Attribute
    {
        return Attribute::make(set: fn ($v) => Str::title(trim((string) $v)));
    }

    /** The surname is held apart so the client list can sort by family name. */
    protected function lastName(): Attribute
    {
        return Attribute::make(set: fn ($v) => filled($v) ? Str::title(trim((string) $v)) : $v);
    }

    /** "Juan Dela Cruz" — what to show wherever the client is named. */
    public function fullName(): string
    {
        return trim($this->name.' '.$this->last_name);
    }

    /** "Dela Cruz, Juan" — for lists that read better by surname. */
    public function listName(): string
    {
        return filled($this->last_name)
            ? trim($this->last_name.', '.$this->name)
            : (string) $this->name;
    }

    /** Client list ordered by surname, then first name. */
    public function scopeBySurname($query)
    {
        return $query->orderByRaw('COALESCE(NULLIF(last_name, ""), name)')->orderBy('name');
    }

    /** Same for the company name (left blank if empty). */
    protected function company(): Attribute
    {
        return Attribute::make(set: fn ($v) => filled($v) ? Str::title(trim((string) $v)) : $v);
    }

    /** Addresses are places — Title Case them too so they read consistently. */
    protected function officeAddress(): Attribute
    {
        return Attribute::make(set: fn ($v) => filled($v) ? Str::title(trim((string) $v)) : $v);
    }

    protected function deliveryAddress(): Attribute
    {
        return Attribute::make(set: fn ($v) => filled($v) ? Str::title(trim((string) $v)) : $v);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(ProductionOrder::class);
    }

    /** Every time this client asked about a job, ordered or not. */
    public function inquiries(): HasMany
    {
        return $this->hasMany(Inquiry::class);
    }

    /** The officer who first wrote this client down. */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
