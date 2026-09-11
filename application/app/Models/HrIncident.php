<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Something that happened, written down while it is still accurate. */
class HrIncident extends Model
{
    use HasFactory, SoftDeletes;

    public const KINDS = [
        'note' => 'Note',
        'tardiness' => 'Late / absent',
        'damage' => 'Damage or loss',
        'conduct' => 'Conduct',
        'warning' => 'Written warning',
        'commendation' => 'Commendation',
    ];

    protected $fillable = [
        'hr_employee_id', 'occurred_on', 'kind', 'description',
        'action_taken', 'acknowledged_at', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'occurred_on' => 'date',
            'acknowledged_at' => 'datetime',
        ];
    }

    public function employee()
    {
        return $this->belongsTo(HrEmployee::class, 'hr_employee_id');
    }

    public function kindLabel(): string
    {
        return self::KINDS[$this->kind] ?? ucfirst((string) $this->kind);
    }

    /** They have seen it. Not that they agree with it. */
    public function isAcknowledged(): bool
    {
        return $this->acknowledged_at !== null;
    }
}
