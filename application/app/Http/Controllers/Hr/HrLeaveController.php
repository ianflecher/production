<?php

namespace App\Http\Controllers\Hr;

use App\Http\Controllers\Controller;
use App\Models\HrEmployee;
use App\Models\HrRequest;
use App\Support\LeaveBalance;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Every request in the shop, on one screen.
 *
 * Until now a leave request could only be answered from inside the person's
 * own employment file, which means HR had to open thirty people one at a time
 * to find out whether anybody was waiting. Nothing anywhere said "four people
 * are waiting on you", so a request sat until the person came and asked -
 * which is the thing a request is supposed to replace.
 *
 * Taken from the shop's own TGIF HR module, which has this screen and an
 * attendance one beside it.
 */
class HrLeaveController extends Controller
{
    private function assertAccess(Request $request): void
    {
        abort_unless($request->user()->canUseHr(), 403);
    }

    public function index(Request $request): View
    {
        $this->assertAccess($request);

        $status = (string) $request->query('status', HrRequest::STATUS_PENDING);
        $type = (string) $request->query('type', '');
        $search = trim((string) $request->query('q', ''));

        // A screen called Leave shows leave. Overtime, undertime, official
        // business and a change of schedule are arrangements ABOUT a working
        // day rather than days off one, and they were crowding out the thing
        // the desk opened this page to answer - so they are behind a filter
        // rather than mixed in.
        $kinds = match ($type) {
            '' => HrRequest::DAYS_AWAY,
            'arrangements' => array_values(array_diff(array_keys(HrRequest::TYPES), HrRequest::DAYS_AWAY)),
            default => [$type],
        };

        $requests = HrRequest::with(['employee.user', 'decidedBy'])
            ->when($status !== 'all', fn ($q) => $q->where('status', $status))
            ->whereIn('type', $kinds)
            ->when($search !== '', fn ($q) => $q->whereHas('employee',
                fn ($e) => $e->where('position', 'like', "%{$search}%")
                    ->orWhereHas('user', fn ($u) => $u->where('name', 'like', "%{$search}%"))))
            ->orderByRaw("CASE WHEN status = '".HrRequest::STATUS_PENDING."' THEN 0 ELSE 1 END")
            ->orderByDesc('starts_on')
            ->get();

        return view('hr.leave.index', [
            'requests' => $requests,
            'status' => $status,
            'type' => $type,
            'search' => $search,
            'types' => HrRequest::TYPES,
            // Unaffected by whatever filter is showing, and counting leave
            // only, to match what this screen is for.
            'waiting' => HrRequest::where('status', HrRequest::STATUS_PENDING)
                ->whereIn('type', HrRequest::DAYS_AWAY)->count(),
            'waitingArrangements' => HrRequest::where('status', HrRequest::STATUS_PENDING)
                ->whereNotIn('type', HrRequest::DAYS_AWAY)->count(),
            'thisMonth' => HrRequest::where('status', HrRequest::STATUS_APPROVED)
                ->whereIn('type', HrRequest::DAYS_AWAY)
                ->whereYear('starts_on', now()->year)
                ->whereMonth('starts_on', now()->month)
                ->sum('working_days'),
            // Who is off today, which is the question the floor asks HR most.
            'offToday' => HrRequest::with('employee.user')
                ->where('status', HrRequest::STATUS_APPROVED)
                ->whereIn('type', HrRequest::DAYS_AWAY)
                ->whereDate('starts_on', '<=', now()->toDateString())
                ->where(fn ($q) => $q->whereDate('ends_on', '>=', now()->toDateString())
                    ->orWhereNull('ends_on'))
                ->get(),
        ]);
    }

    /**
     * The certificate somebody attached.
     *
     * Streamed through here rather than linked from public/, because a
     * medical certificate is not a thing to leave on a guessable URL.
     */
    public function attachment(Request $request, HrRequest $hrRequest): StreamedResponse
    {
        // HR, or the person it is about. Nobody else, not even to guess at
        // whether a request exists.
        $mine = HrEmployee::forUser($request->user())?->id === $hrRequest->hr_employee_id;

        abort_unless($mine || $request->user()->canUseHr(), 403);
        abort_unless($hrRequest->attachment_path, 404);
        abort_unless(Storage::exists($hrRequest->attachment_path), 404);

        return Storage::download(
            $hrRequest->attachment_path,
            $hrRequest->attachment_name ?: 'attachment',
        );
    }

    /** One person's balances, for the panel beside their request. */
    public static function balancesFor(?HrEmployee $employee): array
    {
        if (! $employee) {
            return ['vacation' => null, 'sick' => null];
        }

        return [
            'vacation' => LeaveBalance::for($employee),
            'sick' => LeaveBalance::sick($employee),
        ];
    }
}
