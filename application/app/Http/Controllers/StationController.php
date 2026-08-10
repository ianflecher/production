<?php

namespace App\Http\Controllers;

use App\Models\ProductionOrder;
use App\Models\StationSession;
use App\Models\Task;
use App\Services\Stations;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\View\View;

/**
 * The station board: who is on each printer, press, cutting table, pairing,
 * sewing or QC bench right now, plus the handover log (breaks, shift changes).
 */
class StationController extends Controller
{
    /** Only machine operators (supply chain, production) and leaders/admin. */
    private function assertAccess(): void
    {
        abort_unless(auth()->user()?->canUseStations(), 403);
    }

    /**
     * Job orders a station may be started on: active orders whose matching step
     * has actually been released — which only happens once the leader has
     * approved the mockup and template. Nothing earlier reaches the floor.
     */
    public static function eligibleOrders(string $station)
    {
        $departments = Stations::departments($station);

        $query = ProductionOrder::where('status', 'active')
            ->whereHas('tasks', fn ($q) => $q->whereIn('department', $departments)
                ->whereIn('status', Stations::RELEASED))
            ->orderByDesc('id');

        // A printer only gets the jobs whose job order actually picked THAT
        // printer — an Atexco job shouldn't show up on the DTF machine.
        if (str_starts_with($station, 'printer_')) {
            $printer = substr($station, strlen('printer_'));
            $query->whereHas('jobOrder', fn ($q) => $q->where('printer', $printer));
        }

        return $query;
    }

    /**
     * The jobs sitting at one station, newest first, each tagged with the step
     * that is actually waiting there. Works off collections already in memory
     * so the board doesn't query per station.
     *
     * @param  \Illuminate\Support\Collection<int, ProductionOrder>  $orders  keyed by id
     * @param  \Illuminate\Support\Collection<string, \Illuminate\Support\Collection<int, int>>  $ordersByDepartment
     */
    /**
     * One page of a station's queue, late work first — whoever takes the station
     * should see what is holding the shop up before anything else.
     *
     * Each station pages on its own parameter, so turning the page on the busy
     * machine doesn't move every other card on the board.
     *
     * @param  \Illuminate\Support\Collection  $waiting
     */
    private function queuePage(string $station, $waiting): LengthAwarePaginator
    {
        $sorted = $waiting->sortBy(fn ($o) => match ($o->delayState()) {
            'delayed' => 0,
            'at_risk' => 1,
            default => 2,
        })->values();

        $pageName = 'q_'.preg_replace('/[^a-z0-9]/i', '', $station);
        $page = LengthAwarePaginator::resolveCurrentPage($pageName);

        return new LengthAwarePaginator(
            $sorted->forPage($page, self::PER_PAGE)->values(),
            $sorted->count(),
            self::PER_PAGE,
            $page,
            [
                'path' => LengthAwarePaginator::resolveCurrentPath(),
                'pageName' => $pageName,
                'query' => request()->query(),
            ]
        );
    }

    private static function ordersWaitingAt(string $station, $orders, $ordersByDepartment)
    {
        // A printer only gets the jobs whose job order actually picked THAT
        // printer — an Atexco job shouldn't show up on the DTF machine.
        $printer = str_starts_with($station, 'printer_')
            ? substr($station, strlen('printer_'))
            : null;

        $waiting = collect();

        foreach (Stations::departments($station) as $department) {
            foreach ($ordersByDepartment->get($department, collect()) as $id) {
                $order = $orders->get($id);

                if (! $order || $waiting->has($id)) {
                    continue;
                }
                if ($printer !== null && $order->jobOrder?->printer !== $printer) {
                    continue;
                }

                // Which step of this order is waiting here — a printer can be
                // holding the first sample or the main run, and they read very
                // differently. Each station gets its own copy so one board entry
                // can't overwrite another's step.
                $atStation = clone $order;
                $atStation->station_step = $department;

                $waiting->put($id, $atStation);
            }
        }

        return $waiting->sortKeysDesc()->values();
    }

    public function index(): View
    {
        $this->assertAccess();

        // Only the stations this person's role covers (leaders/admin get all).
        $allowed = Stations::forUser(auth()->user());
        $groups = [];

        // One query for the lot, then split by station GROUP — Printing, Cutting,
        // Production Line and so on each get their own handover log, instead of
        // everything sharing a single list.
        $all = Stations::all();

        $historyByGroup = StationSession::with(['user', 'order'])
            ->whereIn('station', $allowed)
            ->latest('id')
            ->limit(400)
            ->get()
            ->groupBy(fn ($s) => $all[$s->station]['group'] ?? 'Other');

        // The board draws ~30 stations. Everything they need is fetched here in
        // three queries and then matched up in memory — asking per station cost
        // a query each for the session, the jobs and every job's waiting step,
        // which is barely noticeable on a local database and very slow on a
        // remote one.
        $activeByStation = StationSession::with(['user', 'order'])
            ->whereIn('station', $allowed)
            ->whereNull('ended_at')
            ->latest('id')
            ->get()
            ->groupBy('station');

        // Every step released to the floor, and the job it belongs to.
        $released = Task::whereIn('status', Stations::RELEASED)
            ->whereHas('order', fn ($q) => $q->where('status', 'active'))
            ->get(['production_order_id', 'department']);

        $ordersByDepartment = $released->groupBy('department')
            ->map(fn ($rows) => $rows->pluck('production_order_id')->unique()->values());

        $orders = ProductionOrder::with('jobOrder')
            ->whereIn('id', $released->pluck('production_order_id')->unique())
            ->get()
            ->keyBy('id');

        foreach (Stations::grouped() as $group => $stations) {
            foreach ($stations as $key => $s) {
                if (! in_array($key, $allowed, true)) {
                    continue;
                }
                // The operator needs to know how many and what to make, so
                // carry the job order details onto the board.
                $waiting = self::ordersWaitingAt($key, $orders, $ordersByDepartment);

                $groups[$group][] = [
                    'key' => $key,
                    'label' => $s['label'],
                    'session' => $activeByStation->get($key)?->first(),
                    'orders' => $waiting,
                    // A busy station can have hundreds of jobs queued behind it.
                    // Drawing them all made this the heaviest page in the app, so
                    // the card shows a page at a time.
                    'queue' => $this->queuePage($key, $waiting),
                ];
            }
        }

        // Sewer names and thread codes already used on other jobs, so the
        // floor picks from a list instead of retyping (and misspelling) them.
        // One query, not one per suggestable field — this is a board the shop
        // leaves open all day.
        $suggest = \App\Models\JobOrder::stationSuggestions();

        return view('stations.index', [
            'groups' => $groups,
            'historyByGroup' => $historyByGroup,
            'reasons' => StationSession::REASONS,
            'sewerNames' => $suggest['sewer'],
            'threadCodes' => $suggest['thread'],
        ]);
    }

    /** Take a station. Anyone already on it is handed over automatically. */
    public function start(Request $request): RedirectResponse
    {
        $this->assertAccess();
        $data = $request->validate([
            'station' => ['required', 'in:'.implode(',', Stations::keys())],
            // Accounts get shared on the floor, so the operator types their name.
            'operator_name' => ['required', 'string', 'max:100'],
            'production_order_id' => ['required', 'integer', 'exists:production_orders,id'],
            'note' => ['nullable', 'string', 'max:255'],
            // Why the person currently on it is coming off (take-over only).
            'handover_reason' => ['nullable', 'in:break,shift_change'],
        ], [
            'operator_name.required' => 'Enter the name of the person running it.',
            'production_order_id.required' => 'Choose the job order this run is for.',
        ]);

        // A worker may only start the stations their role covers.
        abort_unless(in_array($data['station'], Stations::forUser($request->user()), true), 403);

        // Guard the list server-side too — not just the dropdown. Taking over a
        // run already on this station is always allowed: it's the same job, just
        // a different operator.
        $current = StationSession::activeOn($data['station']);
        $isTakeOverOfSameJob = $current
            && (int) $current->production_order_id === (int) $data['production_order_id'];

        if (! $isTakeOverOfSameJob
            && ! self::eligibleOrders($data['station'])->whereKey($data['production_order_id'])->exists()) {
            return back()->withErrors([
                'production_order_id' => 'That job order has not reached this station yet — the leader still needs to approve it.',
            ]);
        }

        $operator = trim($data['operator_name']);

        // Taking over is where we record WHY the last person came off — a break
        // or a shift change. Finishing the job is a separate action.
        if ($current = StationSession::activeOn($data['station'])) {
            $reason = $data['handover_reason'] ?? 'shift_change';

            $current->update([
                'ended_at' => now(),
                'end_reason' => $reason,
                'note' => $current->note ?: 'Handed over to '.$operator,
            ]);
        }

        StationSession::create([
            'station' => $data['station'],
            'user_id' => $request->user()->id,
            'operator_name' => $operator,
            'production_order_id' => $data['production_order_id'],
            'started_at' => now(),
            'note' => $data['note'] ?? null,
        ]);

        // Show the real person on the pipeline, not the shared account.
        $this->stampOperator($data['production_order_id'], $data['station'], $operator);

        return back()->with('success', "{$operator} is now on the ".Stations::label($data['station']).'.');
    }

    /**
     * Floor accounts are shared, so the pipeline should name whoever typed their
     * name at the station rather than the account the task was assigned to.
     */
    private function stampOperator(int $orderId, string $station, string $operator): void
    {
        $order = ProductionOrder::find($orderId);
        if (! $order) {
            return;
        }

        // Only the step actually being worked — never overwrite the name on a
        // step someone else already finished.
        $order->tasks()
            ->whereIn('department', Stations::departments($station))
            ->whereIn('status', Stations::RELEASED)
            ->update(['operator_name' => $operator]);

        // Someone is now running it, so the step is IN PROGRESS (was READY / a
        // revision). Leave finished / for-checking steps alone.
        $order->tasks()
            ->whereIn('department', Stations::departments($station))
            ->whereIn('status', ['ready', 'revision_required'])
            ->update(['status' => 'in_progress']);
    }

    /** Come off a station — break, shift change, or the job is finished. */
    /**
     * The parts of the job order sheet this station is responsible for.
     *
     * Empty for most stations — a printer has nothing to write on the sheet
     * beyond its operator name, which is stamped automatically.
     *
     * @return array<int, string>
     */
    public static function sheetFieldsFor(string $station): array
    {
        return match (true) {
            str_starts_with($station, 'sewing_') => \App\Models\JobOrder::SEWING_STATION_FIELDS,
            str_starts_with($station, 'qc_') => \App\Models\JobOrder::QC_STATION_FIELDS,
            default => [],
        };
    }

    /** The label the paper form uses for a sheet field, for the station board. */
    public static function sheetFieldLabel(string $field): string
    {
        return match ($field) {
            'neck_label_thread' => 'Neck label — thread',
            'bottom_hem_thread' => 'Bottom hem — thread',
            'neckbond_sewer' => 'Neckbond shoulder — sewer',
            'neckbond_thread' => 'Neckbond shoulder — thread',
            'hangtag_woven_sewer' => 'Top/neck/hangtag woven — sewer',
            'hangtag_woven_thread' => 'Top/neck/hangtag woven — thread',
            'flatbed_sewer' => 'Flatbed — sewer',
            'flatbed_thread' => 'Flatbed — thread',
            'close_side_sewer' => 'Close side body & sleeve — sewer',
            'close_side_thread' => 'Close side body & sleeve — thread',
            'attached_sleeve_sewer' => 'Attached sleeve / cuffs — sewer',
            'attached_sleeve_thread' => 'Attached sleeve / cuffs — thread',
            'topping_side_sewer' => 'Topping side / sleeve — sewer',
            'topping_side_thread' => 'Topping side / sleeve — thread',
            'pipping_sewer' => 'Pipping — sewer',
            'pipping_thread' => 'Pipping — thread',
            'extra_seam_note' => 'Spare column — note',
            'extra_seam_sewer' => 'Spare column — sewer',
            'sewer_notes' => 'Notes from sewer',
            'qc_notes' => 'Notes from QC',
            default => ucfirst(str_replace('_', ' ', $field)),
        };
    }

    public function end(Request $request, StationSession $stationSession): RedirectResponse
    {
        $this->assertAccess();
        abort_unless($stationSession->isRunning(), 403);

        // The sheet asks this station for its own part of the record — which
        // sewer ran each seam, what the checker found. Only the person holding
        // the garment knows, so it is asked here rather than of the account
        // officer weeks earlier.
        $sheetFields = self::sheetFieldsFor($stationSession->station);

        $data = $request->validate([
            'end_reason' => ['required', 'in:'.implode(',', array_keys(StationSession::REASONS))],
            'note' => ['nullable', 'string', 'max:255'],
            ...array_fill_keys(
                array_map(fn ($f) => 'sheet.'.$f, $sheetFields),
                ['nullable', 'string', 'max:1000']
            ),
        ]);

        $stationSession->update([
            'ended_at' => now(),
            'end_reason' => $data['end_reason'],
            'note' => $data['note'] ?? $stationSession->note,
        ]);

        // "Finished" means the work at this station is done, so close the matching
        // task — that's what unlocks the next stage and eventually completes the
        // order. Breaks and shift changes leave the task open.
        $note = '';
        if ($data['end_reason'] === 'done' && $stationSession->order) {
            $departments = Stations::departments($stationSession->station);

            // Credit whoever actually finished it.
            $this->stampOperator(
                $stationSession->production_order_id,
                $stationSession->station,
                $stationSession->operator_name ?: ($stationSession->user?->name ?? '')
            );

            $operator = $stationSession->operator_name ?: ($stationSession->user?->name ?? null);

            // Write this station's part of the job order sheet. Only what was
            // actually typed — a blank box left alone must not wipe what an
            // earlier shift already recorded.
            if ($sheetFields !== [] && $stationSession->order->jobOrder) {
                $typed = array_filter(
                    $request->input('sheet', []),
                    fn ($v, $k) => in_array($k, $sheetFields, true) && filled($v),
                    ARRAY_FILTER_USE_BOTH
                );

                if ($typed !== []) {
                    $stationSession->order->jobOrder->update($typed);
                }
            }

            $closed = 0;
            $forApproval = 0;

            // Only the step actually released to this station — a printer covers
            // printing, the first sample AND mass production, so closing every
            // unfinished one would skip whole stages.
            foreach ($stationSession->order->tasks()
                ->whereIn('department', $departments)
                ->whereIn('status', Stations::RELEASED)
                ->get() as $task) {
                if ($task->approver_role === 'sales') {
                    // The first sample isn't finished until the client has seen it,
                    // so it goes to the account officer instead of straight through.
                    $task->sendForApproval($operator);
                    $forApproval++;
                } else {
                    $task->forceComplete();
                    $closed++;
                }
            }

            if ($forApproval > 0) {
                $note = ' Sent to the account officer to check with the client.';
            } elseif ($closed > 0) {
                $note = ' '.$stationSession->order->order_number.' moved on to the next step.';
            }
        }

        return back()->with('success', $stationSession->stationLabel().' — '.$stationSession->reasonLabel().' recorded.'.$note);
    }
}
