<?php

namespace App\Console\Commands;

use App\Models\Task;
use Illuminate\Console\Command;

/**
 * Tech pack steps sitting on an artist's desk that the artist cannot open.
 *
 * The pack is released to the artist when the account officer sends it. The
 * button that starts the step never checked that, so a step could be moved to
 * IN PROGRESS and the pack would then refuse to open — the job reads as being
 * worked on, by somebody who cannot see it, on every board in the shop. It is
 * the worst state a job can be in: not waiting anywhere anybody would chase
 * it, and not moving either.
 *
 * TaskController::start() now refuses that, so no new ones appear. This is for
 * the ones already in it. Putting the step back to TODO is the honest state —
 * unlockStage() releases it again by itself the moment the officer sends the
 * pack, and the assignee is left alone so it goes back to the same artist.
 *
 * Reports by default and changes nothing; --fix does the work. Same shape as
 * orders:stalled, and for the same reason: a command that writes to live on
 * being typed is a command somebody types by accident.
 */
class FindStuckTechPacks extends Command
{
    protected $signature = 'tech-packs:stuck {--fix : Put the steps back to TODO}';

    protected $description = 'Find tech pack steps an artist holds but cannot open';

    /**
     * Statuses that read as the artist's to act on.
     *
     * for_checking and complete are deliberately not here: those are on an
     * approver's desk, not the artist's, and pulling one back to TODO would
     * take it off somebody's review list. If one is ever found in that state
     * it is a different bug and wants looking at rather than resetting.
     */
    private const THEIRS_TO_ACT_ON = ['ready', 'in_progress', 'revision_required', 'on_hold'];

    public function handle(): int
    {
        $stuck = Task::with('order.jobOrder', 'assignee')
            ->whereIn('status', self::THEIRS_TO_ACT_ON)
            ->where(fn ($q) => $q
                ->where('department', 'like', 'Tech pack%')
                ->orWhere('department', 'like', 'Production template%'))
            ->get()
            ->filter(fn (Task $task) => $task->order?->jobOrder?->status !== 'sent_to_artist');

        if ($stuck->isEmpty()) {
            $this->info('No stuck tech packs. Every one an artist holds can actually be opened.');

            return self::SUCCESS;
        }

        $this->warn($stuck->count().' tech pack step(s) an artist cannot open:');

        foreach ($stuck as $task) {
            $this->line(sprintf(
                '  %s  %-28s  %-16s  %s  (job order: %s)',
                $task->order?->order_number ?? '?',
                $task->department,
                $task->status,
                $task->assignee?->name ?? 'unassigned',
                $task->order?->jobOrder?->status ?? 'none'
            ));
        }

        if (! $this->option('fix')) {
            $this->newLine();
            $this->comment('Nothing changed. Run again with --fix to put them back to TODO.');

            return self::SUCCESS;
        }

        foreach ($stuck as $task) {
            // Status only. The assignee stays, so when the officer sends the
            // pack it opens to the same artist who was already given it, and
            // released_at keeps the day it first reached them.
            $task->update(['status' => 'todo']);
        }

        $this->newLine();
        $this->info($stuck->count().' step(s) put back to TODO. They open by themselves when the pack is sent.');

        return self::SUCCESS;
    }
}
