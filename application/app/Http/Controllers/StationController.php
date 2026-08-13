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
    /**
     * Jobs this station has already finished, on orders that are still running.
     *
     * A sewer who spots a wrong thread code an hour later needs the job to
     * still be there. It drops off the board when the whole order is finished,
     * at which point the sheet is a record and stops being editable.
     *
     * @return \Illuminate\Support\Collection<int, ProductionOrder>
     */
    private static function finishedHere(string $station, $orders, $finishedByDepartment): \Illuminate\Support\Collection
    {
        if (self::sheetFieldsFor($station) === []) {
            return collect();
        }

        $ids = collect(Stations::departments($station))
            ->flatMap(fn ($d) => ($finishedByDepartment[$d] ?? collect())->all())
            ->unique();

        return $ids->map(fn ($id) => $orders[$id] ?? null)
            ->filter()
            ->filter(fn ($o) => $o->sheetStillEditable())
            ->values();
    }

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

        // order.jobOrder as well: a sewing row names its people off the sheet
        // (handoverOperator), which was a query per row on a 400-row list.
        $historyByGroup = StationSession::with(['user', 'order.jobOrder'])
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
        // order.jobOrder too: the card reads the names off the sheet, and
        // fetching it per card put the board back over its query budget.
        $activeByStation = StationSession::with(['user', 'order.jobOrder'])
            ->whereIn('station', $allowed)
            ->whereNull('ended_at')
            ->latest('id')
            ->get()
            ->groupBy('station');

        // Every step released to the floor, plus the sewing and QC steps that
        // are already DONE on a live order — those stay on their card so the
        // sheet can still be corrected. One query for both: this board is left
        // open all day and each query on it is paid over and over.
        $steps = Task::where(fn ($q) => $q
                ->whereIn('status', Stations::RELEASED)
                ->orWhere(fn ($w) => $w->where('status', 'complete')
                    ->whereIn('department', ['Sewing', 'Quality control'])))
            ->whereHas('order', fn ($q) => $q->where('status', 'active'))
            ->get(['production_order_id', 'department', 'status']);

        $released = $steps->whereIn('status', Stations::RELEASED);

        $ordersByDepartment = $released->groupBy('department')
            ->map(fn ($rows) => $rows->pluck('production_order_id')->unique()->values());

        // Done here, nothing of this department left open on that order.
        $finishedByDepartment = $steps->groupBy('department')->map(
            fn ($rows) => $rows->groupBy('production_order_id')
                ->filter(fn ($forOrder) => $forOrder->every(fn ($t) => $t->status === 'complete'))
                ->keys()
                ->values()
        );

        $orders = ProductionOrder::with('jobOrder')
            ->whereIn('id', $steps->pluck('production_order_id')->unique())
            ->get()
            ->keyBy('id');

        // Which orders are on a machine right now, listed under the DEPARTMENT
        // that machine does. Ten sewing cards show the same queue, so a job
        // already on one of them has to drop off the other nine or two people
        // pick it up and only find out at the seam.
        //
        // By department, not globally: an order can honestly be in two places
        // at once — its stickers printing while the shirts wait for Atexco —
        // and hiding it everywhere made it invisible to the station that was
        // genuinely waiting for it.
        $busyByDepartment = [];
        foreach ($activeByStation->flatten(1) as $session) {
            if ($session->production_order_id === null) {
                continue;
            }
            foreach (Stations::departments($session->station) as $department) {
                $busyByDepartment[$department][$session->production_order_id] = true;
            }
        }

        foreach (Stations::grouped() as $group => $stations) {
            foreach ($stations as $key => $s) {
                if (! in_array($key, $allowed, true)) {
                    continue;
                }
                // The operator needs to know how many and what to make, so
                // carry the job order details onto the board.
                $waiting = self::ordersWaitingAt($key, $orders, $ordersByDepartment);

                // Dropped here rather than in the view so the count on the
                // badge, the list under it and the job-order dropdown all say
                // the same thing. They did not: the badge counted a job the
                // list hid, and starting the station offered it anyway.
                $busyHere = array_merge(
                    ...array_map(
                        fn ($d) => array_keys($busyByDepartment[$d] ?? []),
                        Stations::departments($key)
                    ) ?: [[]]
                );

                if ($busyHere !== []) {
                    $waiting = $waiting->reject(fn ($o) => in_array($o->id, $busyHere, true))->values();
                }

                $groups[$group][] = [
                    'key' => $key,
                    'label' => $s['label'],
                    'session' => $activeByStation->get($key)?->first(),
                    'orders' => $waiting,
                    'finished' => self::finishedHere($key, $orders, $finishedByDepartment),
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
        // At sewing the names go on the sheet, seam by seam, when the work is
        // finished — so the machine is taken without being asked who is on it.
        $station = (string) $request->input('station');
        $namesComeFromTheSheet = str_starts_with($station, 'sewing_') || str_starts_with($station, 'qc_');

        $data = $request->validate([
            'station' => ['required', 'in:'.implode(',', Stations::keys())],
            // Accounts get shared on the floor, so the operator types their name.
            'operator_name' => [$namesComeFromTheSheet ? 'nullable' : 'required', 'string', 'max:100'],
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

        // Absent at sewing and QC, where the name is written on the sheet when
        // the work is finished rather than claimed when the machine is taken.
        $operator = trim((string) ($data['operator_name'] ?? ''));

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
        //
        // Sewing is shared: several people run different seams on the same job
        // order, one after another. Replacing the name would credit the whole
        // step to whoever happened to close it, so each new person is added to
        // the list instead.
        foreach ($order->tasks()
            ->whereIn('department', Stations::departments($station))
            ->whereIn('status', Stations::RELEASED)
            ->get() as $task) {
            $names = collect(explode(',', (string) $task->operator_name))
                ->map(fn ($n) => trim($n))
                ->filter()
                ->push(trim($operator))
                ->filter()
                ->unique(fn ($n) => mb_strtolower($n))
                ->implode(', ');

            $task->update(['operator_name' => $names]);
        }

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

    /**
     * Whose name goes on the step.
     *
     * A sewer writes their name against each seam they ran, so asking for it
     * again when they take the machine is the same question twice — and two
     * places for it to disagree. Where the sheet carries names, they are the
     * answer; anywhere else it is whoever took the station.
     *
     * @param  array<string, string>  $typed  what was just written on the sheet
     */
    private static function whoDidTheWork(StationSession $session, array $typed): string
    {
        $fromSheet = collect($typed)
            ->filter(fn ($v, $k) => str_ends_with($k, '_sewer') || $k === 'qc_checked_by')
            ->map(fn ($v) => trim((string) $v))
            ->filter()
            ->unique(fn ($v) => mb_strtolower($v))
            ->implode(', ');

        if ($fromSheet !== '') {
            return $fromSheet;
        }

        return $session->operator_name ?: ($session->user?->name ?? '');
    }

    /**
     * Write this station's part of the sheet. Only what was actually typed — a
     * blank box left alone must not wipe what an earlier shift recorded.
     *
     * @param  array<int, string>  $fields
     * @return array<string, string> what was written
     */
    private function writeSheet(Request $request, StationSession $session, array $fields): array
    {
        if ($fields === [] || ! $session->order?->jobOrder) {
            return [];
        }

        $typed = array_filter(
            $request->input('sheet', []),
            fn ($v, $k) => in_array($k, $fields, true) && filled($v),
            ARRAY_FILTER_USE_BOTH
        );

        if ($typed !== []) {
            $session->order->jobOrder->update($typed);
        }

        return $typed;
    }

    /**
     * The sheet boxes this person may fill, across the stations they work.
     *
     * A sewer owns the seams; the checker owns the QC line. Handing everyone
     * both meant a sewer was asked to sign off the quality check, which is not
     * their job and not their name to write.
     *
     * @return array<int, string>
     */
    public static function sheetFieldsForUser(\App\Models\User $user): array
    {
        $fields = [];

        foreach (Stations::forUser($user) as $station) {
            $fields = array_merge($fields, self::sheetFieldsFor($station));
        }

        return array_values(array_unique($fields));
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
            'qc_checked_by' => 'Quality checked by',
            'qc_notes' => 'Notes from QC',
            default => ucfirst(str_replace('_', ' ', $field)),
        };
    }

    /**
     * The sheet, open for correction, without a run attached.
     *
     * The floor cannot open the order page, so a sewer who spots a wrong
     * thread code after finishing had nowhere to go. This is the same sheet
     * with the same boxes live, reachable from the station board.
     */
    public function editSheet(ProductionOrder $order): View|RedirectResponse
    {
        $this->assertAccess();
        abort_unless($order->jobOrder, 404);

        if (! $order->sheetStillEditable()) {
            return redirect()->route('stations.index')->with('success',
                $order->order_number.' is finished — its sheet is now a record and cannot be changed.');
        }

        return view('stations.sheet', [
            'order' => $order->load(['jobOrder', 'items', 'client', 'creator', 'tasks.assignee', 'tasks.files']),
            'fields' => self::sheetFieldsForUser(request()->user()),
            'suggest' => \App\Models\JobOrder::stationSuggestions(),
        ]);
    }

    /**
     * Correct the floor's part of the sheet after the station has closed.
     *
     * The same fields, the same rule about blanks, but reachable from the job
     * order itself rather than from a run. A seam typed against the wrong row
     * should not be permanent just because somebody pressed Finish.
     */
    public function updateSheet(Request $request, ProductionOrder $order): RedirectResponse
    {
        $this->assertAccess();
        abort_unless($order->sheetStillEditable(), 403);
        abort_unless($order->jobOrder, 404);

        // Only what this person's own stations own.
        $fields = self::sheetFieldsForUser($request->user());

        $request->validate(array_fill_keys(
            array_map(fn ($f) => 'sheet.'.$f, $fields),
            ['nullable', 'string', 'max:1000']
        ));

        // Everything typed, including a box deliberately cleared — this is the
        // correction screen, so blanking one has to mean blanking it.
        $typed = collect($request->input('sheet', []))
            ->only($fields)
            ->map(fn ($v) => filled($v) ? trim((string) $v) : null)
            ->all();

        if ($typed !== []) {
            $order->jobOrder->update($typed);
        }

        return back()->with('success', 'Job order sheet updated.');
    }

    /**
     * Pick up a job order: start the clock and open its sheet.
     *
     * Sewing and QC share one computer and do not sign on to a machine — the
     * job order in front of the operator IS the action. Clicking it is what
     * starts the timer, and finishing the sheet is what stops it, so the two
     * ends of the run are the two things the operator actually does.
     */
    public function work(Request $request, string $station, ProductionOrder $order): RedirectResponse
    {
        $this->assertAccess();

        abort_unless(in_array($station, Stations::keys(), true), 404);
        abort_unless(in_array($station, Stations::forUser($request->user()), true), 403);

        if (! self::eligibleOrders($station)->whereKey($order->id)->exists()) {
            return back()->withErrors([
                'production_order_id' => $order->order_number.' has not reached this station yet.',
            ]);
        }

        // Somebody else's run on this machine ends when this one begins — and
        // so does any run on this JOB somewhere else. A garment is in one pair
        // of hands at a time; without the second clause the same job order sat
        // open on two machines and both of them wrote to the same sheet.
        StationSession::whereNull('ended_at')
            ->where(fn ($q) => $q->where('station', $station)
                ->orWhere('production_order_id', $order->id))
            ->update(['ended_at' => now(), 'end_reason' => 'shift_change']);

        $session = StationSession::create([
            'station' => $station,
            'user_id' => $request->user()->id,
            // No name: it goes on the sheet, with the work.
            'operator_name' => '',
            'production_order_id' => $order->id,
            'started_at' => now(),
        ]);

        return redirect()->route('stations.finish', $session);
    }

    /**
     * The page a sewer or checker gets when they press Finish.
     *
     * Their part of the job order sheet, full width and one screen, instead of
     * a fold-out on the board that is easy to walk past. Submitting it is what
     * closes the step.
     */
    public function finish(StationSession $stationSession): View|RedirectResponse
    {
        $this->assertAccess();

        // The run is already over — usually a board left open in another tab,
        // or the back button after finishing. Say so and send them to the
        // board; a Forbidden page tells somebody who did nothing wrong that
        // they are not allowed, which is both untrue and unhelpful.
        if (! $stationSession->isRunning()) {
            return redirect()->route('stations.index')->with('success',
                $stationSession->stationLabel().' — that run is already finished. '
                .'Pick the job order up again if there is more to do.');
        }

        return view('stations.finish', [
            // Everything the sheet partial reads, so the sewer can see the
            // officer's spec on the same screen instead of opening the job
            // order in another page.
            'session' => $stationSession->load([
                'order.jobOrder', 'order.items', 'order.client',
                'order.creator', 'order.tasks.assignee', 'order.tasks.files',
            ]),
            'fields' => self::sheetFieldsFor($stationSession->station),
            'suggest' => \App\Models\JobOrder::stationSuggestions(),
        ]);
    }

    public function end(Request $request, StationSession $stationSession): RedirectResponse
    {
        $this->assertAccess();

        // Double-submit, or a stale form. Nothing to do, and nothing wrong.
        if (! $stationSession->isRunning()) {
            return redirect()->route('stations.index')
                ->with('success', $stationSession->stationLabel().' — that run was already finished.');
        }

        // The sheet asks this station for its own part of the record — which
        // sewer ran each seam, what the checker found. Only the person holding
        // the garment knows, so it is asked here rather than of the account
        // officer weeks earlier.
        $sheetFields = self::sheetFieldsFor($stationSession->station);

        $data = $request->validate([
            'end_reason' => ['required', 'in:'.implode(',', array_keys(StationSession::REASONS))],
            // "Save and keep working": write the sheet, leave the clock running.
            'keep_working' => ['nullable', 'boolean'],
            'note' => ['nullable', 'string', 'max:255'],
            ...array_fill_keys(
                array_map(fn ($f) => 'sheet.'.$f, $sheetFields),
                ['nullable', 'string', 'max:1000']
            ),
        ]);

        // Stepping away mid-job saves what has been typed without ending the
        // run — leaving the page used to throw it away, which on a sheet with
        // twenty boxes is somebody's whole shift of typing.
        if ($request->boolean('keep_working')) {
            $this->writeSheet($request, $stationSession, $sheetFields);

            return redirect()->route('stations.index')->with('success',
                $stationSession->stationLabel().' — saved. '
                .($stationSession->order?->order_number ?? 'The job').' is still on this machine.');
        }

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

            // Write this station's part of the job order sheet FIRST, so the
            // typing survives even if something later goes wrong.
            $typed = $this->writeSheet($request, $stationSession, $sheetFields);

            // Credit whoever actually did the work.
            //
            // At sewing the names are already on the sheet, seam by seam, so
            // they are read back from there rather than asked for twice. Every
            // other station has one operator and says who at the start.
            $this->stampOperator(
                $stationSession->production_order_id,
                $stationSession->station,
                self::whoDidTheWork($stationSession, $typed)
            );

            $operator = $stationSession->operator_name ?: ($stationSession->user?->name ?? null);

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

        // Same reason as above — the session is finished, so the finish page is
        // no longer somewhere this person is allowed to be.
        return redirect()->route('stations.index')
            ->with('success', $stationSession->stationLabel().' — '.$stationSession->reasonLabel().' recorded.'.$note);
    }
}
