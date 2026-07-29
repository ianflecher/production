<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Client extends Model
{
    protected $fillable = [
        'name', 'contact_number', 'company',
        'office_address', 'delivery_address', 'tin',
        'created_by',
    ];

    /** Store the client name in Title Case so it reads the same everywhere. */
    protected function name(): Attribute
    {
        return Attribute::make(set: fn ($v) => Str::title(trim((string) $v)));
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
}
