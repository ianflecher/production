<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/** A date HR must not miss: a payslip cut-off, or a government remittance. */
class HrDeadline extends Model
{
    use HasFactory, SoftDeletes;

    public const KINDS = [
        'payslip' => 'Payslip',
        'government' => 'Government',
    ];

    protected $fillable = ['label', 'kind', 'due_on', 'note', 'done_at', 'created_by'];

    protected function casts(): array
    {
        return ['due_on' => 'date', 'done_at' => 'datetime'];
    }

    public function isDone(): bool
    {
        return $this->done_at !== null;
    }

    public function isOverdue(): bool
    {
        return ! $this->isDone() && $this->due_on?->isPast();
    }

    public function kindLabel(): string
    {
        return self::KINDS[$this->kind] ?? ucfirst((string) $this->kind);
    }

    /** Still to do, soonest first — what the overview shows. */
    public static function outstanding()
    {
        return self::whereNull('done_at')->orderBy('due_on')->get();
    }
}
