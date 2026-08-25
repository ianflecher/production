<?php

namespace App\Services;

use App\Models\Task;
use App\Models\User;

class StaffAssigner
{
    /**
     * Pick the next person to receive work from a team. First choice is someone
     * present today AND free — they hold no open task, so the whole job stays
     * with one person until it's finished. But when EVERYONE is already busy we
     * don't leave the job unassigned: we fall back to round-robin among the
     * present members (least recently auto-assigned first), so the work still
     * lands on someone. Fair rotation = whoever was auto-assigned least recently.
     * Null only when nobody on that team is available at all.
     */
    public static function next(?string $team): ?User
    {
        if (! $team) {
            return null;
        }

        // The artist leader takes tech packs off the same rotation as the
        // artists he leads — he is one of them, with a checking job on top.
        $roles = $team === User::JOB_ARTIST
            ? [User::JOB_ARTIST, User::JOB_ARTIST_LEAD]
            : [$team];

        $team_members = User::where('is_active', true)
            ->whereIn('job_role', $roles)
            ->with('attendances')
            ->get();

        // Attendance only matters for the artists — their work is handed to a
        // named person. Production and supply run from the station board, so a
        // job must never sit waiting for someone to be "marked present".
        $needsAttendance = $team === User::JOB_ARTIST;

        // Who could take this work at all (present, if attendance applies).
        $available = $team_members->filter(
            fn (User $u) => ! $needsAttendance || $u->isPresentToday()
        );

        // Prefer someone who is free (no open task).
        $candidates = $available->filter(fn (User $u) => self::isFree($u));

        // Nobody free. Rather than leave the job unassigned, round-robin it onto
        // the available members — whoever was assigned least recently gets it.
        if ($candidates->isEmpty()) {
            $candidates = $available;
        }

        // Supply chain must receive the job order and material requests even when
        // nobody has logged in yet — the work still has to land on someone's desk.
        if ($candidates->isEmpty() && self::alwaysReceives($team)) {
            $candidates = $team_members->filter(fn (User $u) => self::isFree($u));

            if ($candidates->isEmpty()) {
                $candidates = $team_members;   // busy but present on paper — still assign
            }
        }

        if ($candidates->isEmpty()) {
            return null;
        }

        $chosen = $candidates->sortBy(fn (User $u) => $u->last_auto_assigned_at?->timestamp ?? -1)->first();

        $chosen->forceFill(['last_auto_assigned_at' => now()])->save();

        return $chosen;
    }

    /**
     * Teams whose work must be handed over regardless of attendance — raw
     * materials, printer and sticker all sit on the supply chain, and the job
     * order / material requests can't sit unreceived waiting for a login.
     */
    public static function alwaysReceives(?string $team): bool
    {
        // The broad supply team plus the specific supply roles it split into.
        return in_array($team, [User::JOB_SUPPLY_CHAIN, 'Raw materials', 'Printer'], true);
    }

    /** A worker is free when they have no open (not complete/cancelled) task. */
    public static function isFree(User $user): bool
    {
        return ! Task::where('assigned_to', $user->id)
            ->whereNotIn('status', ['complete', 'cancelled'])
            ->whereHas('order', fn ($q) => $q->where('status', '!=', 'cancelled'))
            ->exists();
    }

    /**
     * When a worker becomes free, hand them the oldest waiting (released but
     * unassigned) job for their team — so they roll straight onto the next project.
     */
    public static function assignWaitingWork(?User $user): void
    {
        if (! $user || ! $user->job_role || ! $user->isPresentToday() || ! self::isFree($user)) {
            return;
        }

        $task = Task::where('status', 'ready')
            ->where('team', $user->job_role)
            ->whereNull('assigned_to')
            ->whereHas('order', fn ($q) => $q->where('status', 'active'))
            ->orderBy('id')
            ->first();

        if ($task) {
            $task->assignTo($user->id);
            $user->forceFill(['last_auto_assigned_at' => now()])->save();
        }
    }
}
