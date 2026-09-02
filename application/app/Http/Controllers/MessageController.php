<?php

namespace App\Http\Controllers;

use App\Models\AppNotification;
use App\Models\Message;
use App\Models\ProductionOrder;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Conversations that live on a job order. Everyone connected to the order —
 * the account officer who owns it, leaders, and whoever is assigned to its
 * tasks — shares one thread.
 */
class MessageController extends Controller
{
    /** Inbox: every job order thread this person is part of, newest first. */
    public function index(Request $request): View
    {
        $me = $request->user();
        $orderIds = Message::accessibleOrderIds($me);

        // The inbox is one row per job order and grows with the shop, so it
        // needs the same box every other list has: order number, client, or
        // something somebody said.
        $search = trim((string) $request->query('q', ''));

        // The id of each order's newest message. Sorting on this in SQL is what
        // lets the inbox be paged: orders that have been talked about float to
        // the top (no messages sorts last on both MySQL and SQLite), and the
        // rest follow so a thread can still be started on them.
        $lastMessageId = Message::query()
            ->selectRaw('MAX(id)')
            ->whereColumn('production_order_id', 'production_orders.id');

        $orders = ProductionOrder::whereIn('id', $orderIds)
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('order_number', 'like', "%{$search}%")
                ->orWhere('customer_name', 'like', "%{$search}%")
                ->orWhereHas('client', fn ($c) => $c->where('name', 'like', "%{$search}%"))
                // Searching what was said is the point of an inbox search.
                ->orWhereHas('messages', fn ($m) => $m->where('body', 'like', "%{$search}%"))))
            // files come too: the inbox preview describes a photo-only message.
            ->with([
                'client',
                'tasks',
                'messages' => fn ($q) => $q->latest('id')->limit(1),
                'messages.sender',
                'messages.files',
            ])
            // Which orders are this person's is decided by accessibleOrderIds
            // above — including the mover's "once it reaches the printer".
            ->orderByDesc($lastMessageId)
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        // One query for the whole page's unread counts, rather than two per row.
        $unread = Message::unreadCountsForOrders(
            $me,
            $orders->getCollection()->pluck('id')->all()
        );

        $threads = $orders->setCollection(
            $orders->getCollection()->map(fn ($order) => [
                'order' => $order,
                'last' => $order->messages->first(),
                'unread' => (int) ($unread[$order->id] ?? 0),
            ])
        );

        // Layouts still being drawn. They have no job order to be a row
        // against, but the conversation about them belongs in the inbox — it
        // is the same conversation, started before the order existed.
        $layoutThreads = \App\Models\Inquiry::query()
            ->whereNull('production_order_id')
            ->where(fn ($q) => $q->whereNotNull('layout_artist_id')->orWhereHas('messages'))
            ->when(! $me->isLeader(), fn ($q) => $q->where(fn ($w) => $w
                ->where('created_by', $me->id)
                ->orWhere('layout_artist_id', $me->id)
                ->orWhere(fn ($team) => $me->leadsTeam()
                    ? $team->where('team', $me->team)
                    : $team->whereRaw('1 = 0'))))
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w
                ->whereHas('client', fn ($c) => $c->where('name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%"))
                ->orWhereHas('messages', fn ($m) => $m->where('body', 'like', "%{$search}%"))))
            ->with(['client', 'layoutArtist', 'messages' => fn ($q) => $q->latest('id')->limit(1)])
            ->get()
            ->map(fn ($inquiry) => [
                'inquiry' => $inquiry,
                'last' => $inquiry->messages->first(),
                'unread' => 0, // filled in below, in one query for the section
                'stage' => match (true) {
                    $inquiry->layoutApproved() => 'Approved',
                    $inquiry->layoutSubmitted() => 'With the client',
                    $inquiry->layoutWithArtist() => 'Being drawn',
                    default => 'Brief',
                },
            ])
            // Talked-about layouts first, then the quiet ones.
            ->sortByDesc(fn ($row) => $row['last']?->id ?? 0)
            ->values();

        // One query for the whole section, the same way the order rows do it.
        $layoutUnread = Message::unreadCountsForInquiries(
            $me,
            $layoutThreads->pluck('inquiry.id')->all()
        );

        $layoutThreads = $layoutThreads
            ->map(fn ($row) => ['unread' => (int) ($layoutUnread[$row['inquiry']->id] ?? 0)] + $row)
            // An unanswered question outranks an old one nobody is waiting on.
            ->sortByDesc(fn ($row) => [$row['unread'] > 0 ? 1 : 0, $row['last']?->id ?? 0])
            ->values();

        return view('messages.index', [
            'layoutThreads' => $layoutThreads,
            'threads' => $threads,
            'search' => $search,
            // Counted across the whole inbox, not the page on screen.
            'talkedAbout' => ProductionOrder::whereIn('id', $orderIds)
                ->whereHas('messages')
                ->count(),
        ]);
    }

    /**
     * One layout's thread, before the job order exists.
     *
     * Its own page rather than a panel on the layout screen: this is a
     * conversation, and conversations live in Messages. When the order is
     * written these same messages appear on its thread instead.
     */
    public function layout(Request $request, \App\Models\Inquiry $inquiry): View
    {
        abort_unless(Message::canAccessInquiry($request->user(), $inquiry), 403);

        $messages = $inquiry->messages()->with(['sender', 'files'])->orderBy('id')->get();

        // Opening it is reading it — same as a job order's thread.
        Message::markInquiryRead($request->user(), $inquiry->id);

        return view('messages.layout', [
            'inquiry' => $inquiry->load(['client', 'layoutArtist']),
            'messages' => $messages,
        ]);
    }

    /** One job order's thread. Opening it marks the thread read. */
    public function show(Request $request, ProductionOrder $order): View
    {
        $me = $request->user();
        abort_unless(Message::canAccess($me, $order), 403);

        $order->load('tasks');

        // Once a job is hers, the whole conversation is hers — chopping it at
        // the printer only hid the background to what she is chasing.
        $messages = $order->messages()->with(['sender', 'mentions', 'files'])->orderBy('id')->get();

        Message::markRead($me, $order->id);

        return view('messages.show', [
            // Tasks come along so the thread can show where the job actually
            // is — most of what gets asked here is "how far has this got?".
            'order' => $order->load(['client', 'tasks.assignee']),
            'messages' => $messages,
            'participants' => $this->participants($order),
        ]);
    }

    public function store(Request $request, ProductionOrder $order): RedirectResponse
    {
        $me = $request->user();
        abort_unless(Message::canAccess($me, $order), 403);

        // A delivered or cancelled job is finished business. The thread stays
        // readable as a record of what happened; nothing more gets added to it.
        if ($order->conversationClosed()) {
            return back()->withErrors([
                'body' => 'This job order is '.strtolower($order->statusLabel()).' — its conversation is closed.',
            ])->withInput();
        }

        $data = $request->validate([
            // Either is enough — a photo on its own is a perfectly good message.
            'body' => ['nullable', 'string', 'max:5000'],
            'files' => ['nullable', 'array', 'max:10'],
            'files.*' => ['file', 'mimes:jpg,jpeg,png,webp,gif,pdf', 'max:65536'],
            // Shared accounts say who is actually typing.
            'sender_name' => [$me->sharesAccount() ? 'required' : 'nullable', 'string', 'max:100'],
        ], [
            'sender_name.required' => 'Type your name first — this account is shared, so the message needs to say who it is from.',
        ]);

        // Remembered for the rest of the shift, so it is typed once rather than
        // before every message.
        if ($me->sharesAccount()) {
            $request->session()->put('sender_name', trim($data['sender_name']));
        }

        $body = trim((string) ($data['body'] ?? ''));
        $hasFiles = $request->hasFile('files');

        if ($body === '' && ! $hasFiles) {
            return back()->withErrors(['body' => 'Type a message or attach a photo.'])->withInput();
        }

        $participants = $this->participants($order);

        $message = Message::create([
            'production_order_id' => $order->id,
            'sender_id' => $me->id,
            'sender_name' => $me->sharesAccount() ? trim($data['sender_name']) : null,
            'body' => $body !== '' ? $body : null,
        ]);

        if ($hasFiles) {
            foreach ($request->file('files') as $file) {
                $message->files()->create([
                    'path' => $file->store('message-files', 'local'),
                    'original_name' => $file->getClientOriginalName(),
                    'mime' => $file->getClientMimeType(),
                    'size' => $file->getSize(),
                ]);
            }
        }

        // You can only tag someone who is actually in this conversation.
        $mentioned = Message::detectMentions($body, $participants);
        if ($mentioned) {
            $message->mentions()->sync($mentioned);
        }

        Message::markRead($me, $order->id);

        // Tell everyone else on the order, through the existing alert pipeline.
        // Being @mentioned says so, so it stands out from ordinary chatter.
        $preview = $body !== ''
            ? \Illuminate\Support\Str::limit($body, 80)
            : 'Sent a photo.';

        foreach ($participants as $person) {
            if ($person->id === $me->id) {
                continue;
            }

            $wasMentioned = in_array($person->id, $mentioned, true);

            AppNotification::toUser(
                $person->id,
                $wasMentioned
                    ? $me->name.' mentioned you on '.$order->order_number
                    : $me->name.' on '.$order->order_number,
                $preview,
                route('messages.show', $order)
            );
        }

        return redirect()->route('messages.show', $order)->withFragment('end');
    }

    /**
     * Serve a message attachment. Gated by the ORDER the message belongs to, so
     * a file can never be reached by someone outside that conversation.
     */
    public function file(Request $request, \App\Models\MessageFile $file)
    {
        // A file sent on a LAYOUT thread has no order behind it yet, and this
        // asked the order for permission — so every attachment on a layout was
        // a 403 to the two people it was sent to.
        $message = $file->message;
        $order = $message?->order;
        $inquiry = $message?->inquiry;

        $allowed = ($order && Message::canAccess($request->user(), $order))
            || ($inquiry && Message::canAccessInquiry($request->user(), $inquiry));

        abort_unless($allowed, 403);
        abort_unless(\Illuminate\Support\Facades\Storage::disk('local')->exists($file->path), 404);

        return \Illuminate\Support\Facades\Storage::disk('local')
            ->response($file->path, $file->original_name);
    }

    /** Unread total for the nav badge. */
    public function unread(Request $request)
    {
        return response()->json(['unread' => Message::unreadFor($request->user()->id)]);
    }

    /**
     * Everyone in an order's conversation: the officer who owns it, whoever is
     * assigned to its tasks, the leaders/admins — and the mover.
     *
     * The mover is in every conversation whether or not she holds a task on it,
     * because chasing a job is her whole job: she has to be reachable by name on
     * any order without someone first assigning her something.
     */
    private function participants(ProductionOrder $order)
    {
        $assignments = $order->tasks()->whereNotNull('assigned_to')
            ->get(['assigned_to', 'department'])
            ->groupBy('assigned_to')
            ->map(fn ($rows) => $rows->pluck('department')->unique()->values());

        $people = User::where('is_active', true)
            ->where(function ($q) use ($assignments, $order) {
                $q->whereIn('id', $assignments->keys())
                    ->orWhere('id', $order->created_by)
                    ->orWhereIn('job_role', [User::ROLE_LEADER, User::ROLE_SUPER_ADMIN])
                    ->orWhereRaw('LOWER(TRIM(job_role)) = ?', ['mover']);
            })
            ->orderBy('name')
            ->get();

        // What each of them has to do with THIS order, so a wall of first
        // names becomes a list you can address a question to. Two people
        // called Jully are only telling you apart by this.
        foreach ($people as $person) {
            $steps = $assignments[$person->id] ?? collect();

            $person->setAttribute('part_on_order', match (true) {
                $steps->isNotEmpty() => $steps->implode(', '),
                $person->id === $order->created_by => 'Account officer for this order',
                // On every conversation by role, not because of this order.
                default => $person->jobRoleShort() ?: $person->roleLabel(),
            });
        }

        return $people;
    }
}
