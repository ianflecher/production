<?php

namespace App\Http\Controllers;

use App\Models\Attendance;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class UserController extends Controller
{
    public function index(Request $request): View
    {
        // Supervisors only manage the staff in the part of the floor they oversee
        // (Maam Carla → design/agent→artist, Sir Boying → production/printer→QC).
        $scope = $request->user()->managementScope();

        $users = User::with('attendances')->orderBy('name')->get();

        // Thirty-four staff is enough to scroll for. Filtered in PHP because
        // the list is already whole in memory for the counts below.
        $search = trim((string) $request->query('q', ''));

        if ($search !== '') {
            $needle = mb_strtolower($search);
            $users = $users->filter(fn ($u) => str_contains(mb_strtolower((string) $u->name), $needle)
                || str_contains(mb_strtolower((string) $u->email), $needle)
                || str_contains(mb_strtolower((string) $u->job_role), $needle))->values();
        }

        if ($scope) {
            $users = $users
                ->filter(fn ($u) => User::roleDomain($u->job_role) === $scope || $u->id === $request->user()->id)
                ->values();
        }

        // ---- Analytics for the (scoped) staff shown ----
        $staff = $users->where('is_active', true)->values();
        $presentToday = $staff->filter(fn ($u) => $u->isPresentToday())->count();
        $absentToday = max(0, $staff->count() - $presentToday);

        $deptMix = $users->groupBy(fn ($u) => User::department($u->job_role))
            ->map->count()->sortDesc();

        $positionDist = $users->groupBy(fn ($u) => $u->job_role ?: '—')
            ->map->count()->sortDesc();

        // Present count per day for the last 7 days (from attendance records).
        $ids = $users->pluck('id');
        $presentByDate = \App\Models\Attendance::whereIn('user_id', $ids)
            ->where('status', 'present')
            ->whereDate('date', '>=', now()->subDays(6)->toDateString())
            ->get()
            ->groupBy(fn ($a) => $a->date->toDateString())
            ->map->count();

        $sevenDay = collect(range(0, 6))->map(function ($i) use ($presentByDate, $presentToday) {
            $d = now()->subDays(6 - $i);
            // Today reflects the live present count (logins + overrides); past days
            // come from the recorded attendance.
            $count = $d->isToday()
                ? $presentToday
                : (int) ($presentByDate[$d->toDateString()] ?? 0);

            return [
                'label' => $d->format('D'),
                'day' => $d->format('j'),
                'count' => $count,
            ];
        });

        $todayAttendance = \App\Models\Attendance::whereIn('user_id', $ids)
            ->whereDate('date', now()->toDateString())
            ->pluck('status', 'user_id');

        return view('users.index', [
            'users' => $users,
            'search' => $search,
            'managementScope' => $scope,
            'todayAttendance' => $todayAttendance,
            'presentToday' => $presentToday,
            'absentToday' => $absentToday,
            'deptMix' => $deptMix,
            'positionDist' => $positionDist,
            'sevenDay' => $sevenDay,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        /*
         * One position choice covers both the permission role
         * and the production team.
         */
        $allowedPositions = $request->user()->isSuperAdmin()
            ? array_merge(
                array_keys(User::JOB_ROLES),
                [
                    User::ROLE_SALES,
                    User::ROLE_FINANCE,
                    User::JOB_SUPERVISOR,
                    User::ROLE_LEADER,
                    User::ROLE_SUPER_ADMIN,
                ]
            )
            : array_keys(User::JOB_ROLES);

        $data = $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
            ],

            'email' => [
                'required',
                'string',
                'email',
                'max:255',
                'unique:users,email',
            ],

            'password' => [
                'required',
                'string',
                'min:8',
            ],

            'position' => [
                'required',
                Rule::in($allowedPositions),
            ],

            'team' => [
                'nullable',
                Rule::in(array_keys(User::TEAMS)),
            ],
        ]);

        User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $data['password'],

            /*
             * job_role is the source of truth.
             * The permission role is derived from this value.
             */
            'job_role' => $data['position'],

            'team' => $data['position'] === User::ROLE_SALES
                ? ($data['team'] ?? null)
                : null,

            'is_active' => true,
        ]);

        return back()->with(
            'success',
            "Account for {$data['name']} created."
        );
    }

    public function setTeam(
        Request $request,
        User $user
    ): RedirectResponse {
        $data = $request->validate([
            'team' => [
                'nullable',
                Rule::in(array_keys(User::TEAMS)),
            ],
        ]);

        $user->update([
            'team' => $data['team'] ?: null,
        ]);

        return back()->with(
            'success',
            $user->name
            . ' team set to '
            . ($user->teamLabel() ?? 'none')
            . '.'
        );
    }

    /**
     * Activate / deactivate an account. A deactivated user stays in the system
     * (so their history is kept) but EnsureUserIsActive signs them out on their
     * next request — the way to off-board someone without deleting them.
     */
    public function toggle(
        Request $request,
        User $user
    ): RedirectResponse {
        /*
         * You cannot deactivate yourself — that would lock you out
         * of the very page you are standing on.
         */
        if ($user->id === $request->user()->id) {
            throw ValidationException::withMessages([
                'user' => 'You cannot deactivate your own account.',
            ]);
        }

        /*
         * Only a Super Admin may deactivate another Super Admin.
         */
        if (
            $user->isSuperAdmin()
            && ! $request->user()->isSuperAdmin()
        ) {
            abort(403);
        }

        $user->update([
            'is_active' => ! $user->is_active,
        ]);

        return back()->with(
            'success',
            $user->name
            . ($user->is_active
                ? ' can sign in again.'
                : ' has been deactivated and will be signed out.')
        );
    }

    public function resetPassword(
        Request $request,
        User $user
    ): RedirectResponse {
        /*
         * A leader cannot reset the password of a Super Admin.
         */
        if (
            $user->isSuperAdmin()
            && ! $request->user()->isSuperAdmin()
        ) {
            abort(403);
        }

        /*
         * One button, no typing: the account goes back to the standard
         * default and the leader tells the person in the shop what it is.
         */
        $user->update([
            'password' => User::DEFAULT_PASSWORD,
        ]);

        return back()->with(
            'success',
            $user->name
            . ' can now sign in with the default password: '
            . User::DEFAULT_PASSWORD
            . ' — ask them to change it under Account.'
        );
    }

    public function markAttendance(
        Request $request,
        User $user
    ): RedirectResponse {
        $data = $request->validate([
            'status' => [
                'required',
                'in:present,absent',
            ],
        ]);

        Attendance::updateOrCreate(
            [
                'user_id' => $user->id,
                'date' => now()->toDateString(),
            ],
            [
                'status' => $data['status'],
                'set_by' => $request->user()->id,
            ]
        );

        $status = $data['status'] === 'present'
            ? 'marked present'
            : 'marked absent';

        return back()->with(
            'success',
            $user->name
            . ' is '
            . $status
            . ' for today.'
        );
    }

    public function destroy(
        Request $request,
        User $user
    ): RedirectResponse {
        $currentUser = $request->user();

        /*
         * Prevent users from deleting their own account.
         */
        if ($user->id === $currentUser->id) {
            throw ValidationException::withMessages([
                'user' => 'You cannot delete your own account.',
            ]);
        }

        /*
         * Only a Super Admin may delete another Super Admin.
         */
        if (
            $user->isSuperAdmin()
            && ! $currentUser->isSuperAdmin()
        ) {
            abort(403);
        }

        /*
         * Only leaders and Super Admins may delete accounts.
         *
         * Keep this even when your route already has middleware,
         * so the controller remains protected.
         */
        if (
            ! $currentUser->isLeader()
            && ! $currentUser->isSuperAdmin()
        ) {
            abort(403);
        }

        $userName = $user->name;

        // Soft delete: the account is hidden and can no longer sign in, but their
        // orders, tasks and attendance stay intact and the account is recoverable.
        // (No hard delete, so linked records never block it and nothing is lost.)
        $user->delete();

        return back()->with(
            'success',
            $userName . ' has been removed. Their records are kept and the account can be restored.'
        );
    }
}