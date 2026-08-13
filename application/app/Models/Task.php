<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

class Task extends Model
{
    /** Pipeline stages in order (keyed by stage number). */
    public const DEPARTMENTS = [
        1 => 'Layout',
        2 => 'Final mockup & template',
        3 => 'Materials / Printer / Sticker',
        4 => 'Add-ons',
        5 => 'Cutting',
        6 => 'Pairing',
        7 => 'Sewing',
        8 => 'Quality control',
        9 => 'Produce sample for client',
        10 => 'Mass production',
        11 => 'Cutting (batch)',
        12 => 'Pairing (batch)',
        13 => 'Sewing (batch)',
        14 => 'Quality control (batch)',
        15 => 'Inventory',
        16 => 'Release to client',
    ];

    public const STATUS_LABELS = [
        'todo' => 'TO DO',
        'ready' => 'READY',
        'in_progress' => 'IN PROGRESS',
        'for_checking' => 'FOR CHECKING',
        'revision_required' => 'REVISION REQUIRED',
        'complete' => 'COMPLETE',
        'on_hold' => 'ON HOLD',
        'cancelled' => 'CANCELLED',
    ];

    /** A submission may be sent back for revision at most this many times. */
    public const MAX_REVISIONS = 3;

    protected $fillable = [
        'production_order_id', 'sequence', 'stage', 'department', 'team', 'instructions',
        'assigned_to', 'operator_name', 'status', 'approver_role', 'auto_assign', 'auto_submit',
        'revision_note', 'revision_count', 'submitted_at', 'approved_at', 'released_at',
    ];

    protected function casts(): array
    {
        return [
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
            'released_at' => 'datetime',
            'auto_assign' => 'boolean',
            'auto_submit' => 'boolean',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(ProductionOrder::class, 'production_order_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function files(): HasMany
    {
        return $this->hasMany(TaskFile::class)->orderByDesc('id');
    }

    public function latestFile(): ?TaskFile
    {
        return $this->files()->first();
    }

    /**
     * Files this step must hand over when submitted, as slot => label.
     *
     * @return array<string, string>
     */
    public function fileSlots(): array
    {
        if (str_starts_with($this->department, 'Production template')) {
            // The artist only uploads the template — the mockup (from the final
            // mockup step) and the job order come across automatically on submit.
            return ['file' => 'Template file'];
        }

        if (str_starts_with($this->department, 'Final mockup')) {
            return ['file' => 'Final mockup file'];
        }

        if (str_starts_with($this->department, 'Layout')
            || str_starts_with($this->department, 'Design layout')
            || str_starts_with($this->department, 'Design sample')) {
            return ['file' => 'Layout file'];
        }

        if (str_starts_with($this->department, 'Produce sample for client')) {
            return ['file' => 'Photo of the printed piece'];
        }

        // The single Export step hands ALL the print-ready files to production in
        // one go — but each file is entered separately: the print file always,
        // plus the sticker and/or embroidery file when the order needs them. The
        // labels match what each station looks for on its work sheet.
        // ("Export TIFF" is the legacy department name for the same step.)
        if ($this->department === 'Export' || $this->department === 'Export TIFF') {
            $slots = ['print' => 'Print file (TIFF)'];
            if ($this->order->needs_sticker) {
                $slots['sticker'] = 'Sticker file';
            }
            // A back pocket is a separate printed piece, so it needs its own file.
            if ($this->order->back_pocket) {
                $slots['backpocket'] = 'Back pocket file';
            }
            // A press decoration (heat / cap / small / roller) is a separate
            // printed design, so it gets its own export file. Only shown when a
            // press decoration is chosen — embroidery uses its own slot below,
            // and "no decoration" shows nothing here.
            $deco = $this->order->jobOrder?->press;
            if ($deco && $deco !== 'embroidery') {
                $slots['decoration'] = 'Decoration file';
            }
            if ($this->order->jobOrder?->needs_embroidery) {
                $slots['embroidery'] = 'Embroidery file';
            }

            return $slots;
        }
        // Legacy separate export steps (older orders only).
        if ($this->department === 'Export sticker') {
            return ['file' => 'Sticker file'];
        }
        if ($this->department === 'Export embroidery') {
            return ['file' => 'Embroidery file'];
        }

        return [];
    }

    /** The artist export steps that hand print files to production. */
    public function isExportStep(): bool
    {
        return in_array($this->department, ['Export', 'Export TIFF', 'Export sticker', 'Export embroidery'], true);
    }

    /**
     * Only the three export steps are referenced by a network path (the print
     * files handed to production). The layout, mockup and template are uploaded
     * as real files, like payment proof and client uploads.
     */
    public function usesFilePath(): bool
    {
        return $this->isExportStep();
    }

    /** Steps with required file slots can't be submitted empty-handed. */
    public function requiresFile(): bool
    {
        return $this->fileSlots() !== [];
    }

    /**
     * A work step that unlocked but couldn't be auto-assigned because nobody on
     * its team is present today. Needs a login or a manual assignment.
     */
    public function isStuckNoStaff(): bool
    {
        // Attendance only gates the ARTIST steps, where work is handed to a named
        // person from "My Tasks". Everything else is run from the station board —
        // whoever is on the machine types their name, so there is nothing to wait
        // for and no attendance to check.
        if ($this->team !== \App\Models\User::JOB_ARTIST) {
            return false;
        }

        // Only a RELEASED (ready) step can be stuck on staffing. A todo step is
        // still locked behind its dependency, not waiting on a person.
        if ($this->assigned_to || ! $this->team || $this->status !== 'ready') {
            return false;
        }

        // Supply chain always receives its work, so it's never "stuck on staff".
        if (\App\Services\StaffAssigner::alwaysReceives($this->team)) {
            return false;
        }

        return ! \App\Models\User::where('is_active', true)
            ->where('job_role', $this->team)
            ->get()
            ->contains(fn ($u) => $u->isPresentToday());
    }

    public function revisionsLeft(): int
    {
        return max(0, self::MAX_REVISIONS - (int) $this->revision_count);
    }

    public function canRequestRevision(): bool
    {
        return $this->revisionsLeft() > 0;
    }

    public function statusLabel(): string
    {
        return self::STATUS_LABELS[$this->status] ?? strtoupper($this->status);
    }

    public function nextTask(): ?Task
    {
        return $this->order->tasks->firstWhere('sequence', $this->sequence + 1);
    }

    public function approverLabel(): string
    {
        return $this->approver_role === 'sales' ? 'client (via sales)' : 'leader';
    }

    /**
     * Agents may only act while the order itself is active.
     */
    private function assertOrderActive(): void
    {
        if ($this->order->status !== 'active') {
            throw ValidationException::withMessages([
                'task' => 'This production order is currently '.str_replace('_', ' ', $this->order->status).'. No changes are allowed.',
            ]);
        }
    }

    /** Agent: READY (or REVISION REQUIRED) -> IN PROGRESS */
    public function start(User $agent): void
    {
        $this->assertOrderActive();

        if ($this->assigned_to !== $agent->id) {
            abort(403);
        }

        if (! in_array($this->status, ['ready', 'revision_required'], true)) {
            throw ValidationException::withMessages([
                'task' => 'This task cannot be started right now (status: '.$this->statusLabel().').',
            ]);
        }

        $this->update(['status' => 'in_progress']);
    }

    /** Agent: IN PROGRESS -> ON HOLD (pause the work). */
    public function putOnHold(User $agent): void
    {
        if ($this->assigned_to !== $agent->id) {
            abort(403);
        }

        if ($this->status !== 'in_progress') {
            throw ValidationException::withMessages([
                'task' => 'Only a task you are working on can be put on hold.',
            ]);
        }

        $this->update(['status' => 'on_hold']);
    }

    /** Agent: ON HOLD -> IN PROGRESS (resume the work). */
    public function resumeWork(User $agent): void
    {
        $this->assertOrderActive();

        if ($this->assigned_to !== $agent->id) {
            abort(403);
        }

        if ($this->status !== 'on_hold') {
            throw ValidationException::withMessages([
                'task' => 'Only a task on hold can be resumed.',
            ]);
        }

        $this->update(['status' => 'in_progress']);
    }

    /** Agent: IN PROGRESS -> FOR CHECKING */
    public function submitForChecking(User $agent): void
    {
        $this->assertOrderActive();

        if ($this->assigned_to !== $agent->id) {
            abort(403);
        }

        if ($this->status !== 'in_progress') {
            throw ValidationException::withMessages([
                'task' => 'Only a task that is IN PROGRESS can be submitted for checking.',
            ]);
        }

        $this->update(['status' => 'for_checking', 'submitted_at' => now()]);

        // Alert whoever checks it.
        $order = $this->order;
        $url = route('orders.show', $order);

        if ($this->approver_role === 'sales') {
            // The client-review step — tell the account officer who owns the order.
            AppNotification::toUser($order->created_by,
                '🎨 Layout ready for the client',
                "{$order->order_number} — {$this->department} is ready to show the client.", $url);
        } else {
            AppNotification::toRole(User::ROLE_LEADER,
                '📋 Ready to check',
                "{$order->order_number} — {$this->department} was submitted for your approval.", $url);
        }
    }

    /**
     * Where this step is currently sitting, in plain words — so an account
     * officer looking at the pipeline can see who they're waiting on instead of
     * just "READY" with nobody assigned.
     */
    public function whereItSits(): ?string
    {
        if (! in_array($this->status, ['ready', 'in_progress', 'revision_required'], true)) {
            return null;
        }

        if ($this->team === \App\Models\User::JOB_ARTIST) {
            return null;   // the assignee column already names the artist
        }

        return match ($this->department) {
            'Raw materials' => 'waiting at the raw materials desk',
            'Inventory' => 'waiting at the inventory desk',
            'Release to client' => 'with the products desk, to hand over to the client',
            'Produce sample for client' => 'with the account officer, to show the client',
            'Sticker' => 'waiting at the sticker printer',
            'Printer', 'Mass production' => 'waiting at '.($this->order->jobOrder?->printerLabel() ?: 'the printer'),
            default => 'waiting at the '.strtolower($this->department).' station',
        };
    }

    /**
     * Finished at a station, but somebody still has to sign it off — the first
     * sample goes to the account officer to show the client. Puts it FOR
     * CHECKING rather than completing it, so it lands on Sample Review.
     */
    public function sendForApproval(?string $operator = null): void
    {
        if (in_array($this->status, ['complete', 'cancelled'], true)) {
            return;
        }

        $this->update([
            'status' => 'for_checking',
            'submitted_at' => now(),
        ] + ($operator ? ['operator_name' => $operator] : []));

        $order = $this->order;
        $url = route('orders.show', $order);

        if ($this->approver_role === 'sales') {
            AppNotification::toUser(
                $order->created_by,
                '🧵 First sample ready for the client',
                "{$order->order_number} — show the sample to the client, then approve it or ask for changes.",
                route('sample.review'),
            );
        } else {
            AppNotification::toRole(
                User::ROLE_LEADER,
                '📋 Ready to check',
                "{$order->order_number} — {$this->department} was submitted for your approval.",
                $url,
            );
        }
    }

    /**
     * Where the person doing this step actually works. Only artists work from
     * "My Tasks" — the raw-materials and inventory desks have their own pages,
     * and everyone else runs the job from the station board. Sending them all
     * to the task page just dead-ends them.
     */
    public function workUrl(): string
    {
        if ($this->team === \App\Models\User::JOB_ARTIST) {
            return route('tasks.show', $this->id);
        }

        return match ($this->department) {
            'Raw materials' => route('inventory.requests'),
            'Inventory' => route('products.index'),
            'Release to client' => route('products.index'),
            default => route('stations.index'),
        };
    }

    /** Leader: FOR CHECKING -> COMPLETE, then unlock the next task. */
    public function approve(): void
    {
        if ($this->status !== 'for_checking') {
            throw ValidationException::withMessages([
                'task' => 'Only a task that is FOR CHECKING can be approved.',
            ]);
        }

        $this->markComplete();
    }

    /** Leader override: mark COMPLETE from any open status. */
    public function forceComplete(): void
    {
        if (in_array($this->status, ['complete', 'cancelled'], true)) {
            throw ValidationException::withMessages([
                'task' => 'This task is already '.$this->statusLabel().'.',
            ]);
        }

        $this->markComplete();
    }

    private function markComplete(): void
    {
        $assignee = $this->assignee;

        $this->update(['status' => 'complete', 'approved_at' => now()]);

        // The order unlocks the next stage once every task in this stage is done
        // (parallel tasks in the same stage must all finish first).
        $this->order->handleTaskCompleted($this);

        // If the person who finished this is now free, roll them onto the next
        // waiting job for their team (keeps one worker on one project at a time).
        if ($assignee) {
            \App\Services\StaffAssigner::assignWaitingWork($assignee->fresh());
        }
    }

    /** FOR CHECKING -> REVISION REQUIRED. Never unlocks the next task. */
    public function requestRevision(string $note): void
    {
        if ($this->status !== 'for_checking') {
            throw ValidationException::withMessages([
                'task' => 'Only a task that is FOR CHECKING can be sent back for revision.',
            ]);
        }

        if (! $this->canRequestRevision()) {
            throw ValidationException::withMessages([
                'task' => 'Revision limit reached — this work has already been revised '
                    .self::MAX_REVISIONS.' times. Approve it, or ask the leader to step in.',
            ]);
        }

        $this->update([
            'status' => 'revision_required',
            'revision_note' => $note,
            'revision_count' => $this->revision_count + 1,
        ]);

        // Tell the artist their work came back.
        $this->notifyAssignee();
    }

    /** Leader override: unlock a blocked task ahead of its dependency. */
    public function unlock(): void
    {
        if ($this->status !== 'todo') {
            throw ValidationException::withMessages([
                'task' => 'Only a TO DO task can be unlocked.',
            ]);
        }

        $this->update(['status' => 'ready']);
    }

    public function assignTo(?int $userId): void
    {
        if (in_array($this->status, ['complete', 'cancelled'], true)) {
            throw ValidationException::withMessages([
                'task' => 'A '.$this->statusLabel().' task cannot be reassigned.',
            ]);
        }

        $this->update(['assigned_to' => $userId]);
        $this->notifyAssignee();
    }

    /** Alert the assigned worker that a released task is on their desk. */
    public function notifyAssignee(): void
    {
        if (! in_array($this->status, ['ready', 'revision_required'], true)) {
            return;
        }

        $order = $this->order;
        $isRevision = $this->status === 'revision_required';
        $note = \Illuminate\Support\Str::limit((string) $this->revision_note, 80);
        $url = $this->workUrl();

        // Artists are handed work personally, so it goes to that one person.
        if ($this->team === User::JOB_ARTIST) {
            if (! $this->assigned_to) {
                return;
            }

            AppNotification::toUser(
                $this->assigned_to,
                $isRevision ? '↩ Sent back for changes' : '🆕 New task for you',
                $isRevision
                    ? "{$order->order_number} — {$this->department}: {$note}"
                    : "{$order->order_number} — {$this->department} is ready to start.",
                $url,
            );

            return;
        }

        // Everyone else shares a desk or a station board, so tell the whole team
        // and describe the actual job — "a new task" means nothing to them.
        [$title, $body] = match ($this->department) {
            'Raw materials' => [
                '📦 Materials to issue',
                "{$order->order_number} — issue the materials on the job order, or reject if stock is short.",
            ],
            'Inventory' => [
                '📦 Products to count in',
                "{$order->order_number} — finished products are ready to be received.",
            ],
            'Produce sample for client' => [
                '🚚 Sample to deliver',
                "{$order->order_number} — take the sample to the account officer.",
            ],
            default => $isRevision
                ? ['↩ Job sent back', "{$order->order_number} — {$this->department} has to be done again: {$note}"]
                : ['🔧 Job ready at your station', "{$order->order_number} — {$this->department} is ready to run."],
        };

        if ($this->team) {
            AppNotification::toRole($this->team, $title, $body, $url);
        } elseif ($this->assigned_to) {
            AppNotification::toUser($this->assigned_to, $title, $body, $url);
        }
    }
}
