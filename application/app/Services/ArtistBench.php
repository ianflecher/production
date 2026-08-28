<?php

namespace App\Services;

use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Who is at a drawing desk, and what happens to the work when they leave it.
 *
 * An artist's work is handed to a named person, so a queue sitting with
 * somebody who has gone home is a queue nobody is drawing. When an artist
 * signs off, the steps they had NOT STARTED pass to the artists still at a
 * desk; anything in progress stays with them, because a part-drawn layout in
 * somebody else's hands is work done twice.
 *
 * It is a loan. Signing back in takes those steps back — unless the artist who
 * received one has started or finished it, in which case it stays where the
 * work is. Then the bench is levelled: whoever holds least gets the next one,
 * until everybody at a desk holds a similar number.
 *
 * At a desk means signed in AND marked present. Attendance is the day's
 * record; the session is whether they are actually there now.
 */
class ArtistBench
{
    /** The steps that may be passed around: not started, not finished. */
    private const MOVABLE = ['ready'];

    /** Everyone whose work this covers — the artists and the one who leads them. */
    public static function roles(): array
    {
        return [User::JOB_ARTIST, User::JOB_ARTIST_LEAD];
    }

    /**
     * Artists at a desk right now: signed in, and marked present for the day.
     *
     * A session row is Laravel's own record of somebody being signed in. One
     * that has not been touched inside the session lifetime is a browser left
     * open on a machine nobody is at, so it does not count.
     *
     * @return Collection<int, User>
     */
    public static function onDuty(): Collection
    {
        $stale = now()->subMinutes((int) config('session.lifetime', 60))->getTimestamp();

        $signedIn = DB::table('sessions')
            ->whereNotNull('user_id')
            ->where('last_activity', '>=', $stale)
            ->pluck('user_id')
            ->unique();

        return User::where('is_active', true)
            ->whereIn('job_role', self::roles())
            ->whereIn('id', $signedIn)
            ->with('attendances')
            ->get()
            ->filter(fn (User $u) => $u->isPresentToday())
            ->values();
    }

    /** The open steps somebody is holding, for levelling the bench. */
    public static function load(User $artist): int
    {
        return Task::where('assigned_to', $artist->id)
            ->whereNotIn('status', ['complete', 'cancelled', 'todo'])
            ->count();
    }

    /**
     * An artist has signed off: pass on what they had not started.
     *
     * @return int how many steps moved
     */
    public static function handOver(User $leaving): int
    {
        $others = self::onDuty()->reject(fn (User $u) => $u->id === $leaving->id);

        if ($others->isEmpty()) {
            // Nobody is at a desk. The work stays where it is and waits — the
            // next artist to sign in picks it up in takeBack()/levelUp().
            return 0;
        }

        $moving = Task::where('assigned_to', $leaving->id)
            ->whereIn('status', self::MOVABLE)
            ->whereIn('team', self::roles())
            ->orderBy('sequence')
            ->get();

        foreach ($moving as $step) {
            $to = $others->sortBy(fn (User $u) => self::load($u))->first();

            $step->update([
                'assigned_to' => $to->id,
                // Remembered so it can go home again.
                'passed_from' => $step->passed_from ?? $leaving->id,
            ]);
        }

        return $moving->count();
    }

    /**
     * An artist is back: take back what was theirs, then level the bench.
     *
     * @return array{returned: int, taken: int}
     */
    public static function welcomeBack(User $returning): array
    {
        // Theirs, still untouched by whoever was holding it.
        $returned = Task::where('passed_from', $returning->id)
            ->whereIn('status', self::MOVABLE)
            ->whereIn('team', self::roles())
            ->get();

        foreach ($returned as $step) {
            $step->update(['assigned_to' => $returning->id, 'passed_from' => null]);
        }

        // Anything the holder started or finished stops being a loan: it is
        // theirs now, and asking for it back would undo real work.
        Task::where('passed_from', $returning->id)
            ->whereNotIn('status', self::MOVABLE)
            ->update(['passed_from' => null]);

        return ['returned' => $returned->count(), 'taken' => self::levelUp($returning)];
    }

    /**
     * Even out the bench by moving unstarted work onto whoever holds least.
     *
     * Only ever moves a step from somebody holding MORE than the person
     * receiving it, and stops the moment the difference is one — otherwise two
     * artists hand the same step back and forth for ever.
     *
     * @return int how many steps the returning artist picked up
     */
    public static function levelUp(User $returning): int
    {
        $bench = self::onDuty();

        if ($bench->count() < 2 || ! $bench->contains(fn (User $u) => $u->id === $returning->id)) {
            return 0;
        }

        $taken = 0;

        // A ceiling on the loop: at most one pass per step on the bench, so a
        // strange state cannot spin here.
        for ($guard = 0; $guard < 200; $guard++) {
            $mine = self::load($returning);

            $busiest = $bench
                ->reject(fn (User $u) => $u->id === $returning->id)
                ->sortByDesc(fn (User $u) => self::load($u))
                ->first();

            if (! $busiest || self::load($busiest) - $mine < 2) {
                break;      // as even as it can be
            }

            $step = Task::where('assigned_to', $busiest->id)
                ->whereIn('status', self::MOVABLE)
                ->whereIn('team', self::roles())
                ->orderByDesc('sequence')
                ->first();

            if (! $step) {
                break;      // the busiest one has nothing spare to give
            }

            $step->update(['assigned_to' => $returning->id, 'passed_from' => null]);
            $taken++;
        }

        return $taken;
    }
}
