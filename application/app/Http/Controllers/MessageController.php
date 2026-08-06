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

        $orders = ProductionOrder::whereIn('id', $orderIds)
            // files come too: the inbox preview describes a photo-only message.
            ->with([
                'client',
                'messages' => fn ($q) => $q->latest('id')->limit(1),
                'messages.sender',
                'messages.files',
            ])
            ->get();

        $threads = $orders->map(function ($order) use ($me) {
            $last = $order->messages->first();

            return [
                'order' => $order,
                'last' => $last,
                'unread' => Message::unreadInOrder($me, $order->id),
            ];
        })
            // Orders that have been talked about float to the top; the rest
            // follow so a thread can still be started on them.
            ->sortByDesc(fn ($t) => $t['last']?->id ?? 0)
            ->values();

        return view('messages.index', [
            'threads' => $threads,
            'talkedAbout' => $threads->filter(fn ($t) => $t['last'] !== null)->count(),
        ]);
    }

    /** One job order's thread. Opening it marks the thread read. */
    public function show(Request $request, ProductionOrder $order): View
    {
        $me = $request->user();
        abort_unless(Message::canAccess($me, $order), 403);

        $messages = $order->messages()->with(['sender', 'mentions', 'files'])->orderBy('id')->get();

        Message::markRead($me, $order->id);

        return view('messages.show', [
            'order' => $order->load('client'),
            'messages' => $messages,
            'participants' => $this->participants($order),
        ]);
    }

    public function store(Request $request, ProductionOrder $order): RedirectResponse
    {
        $me = $request->user();
        abort_unless(Message::canAccess($me, $order), 403);

        $data = $request->validate([
            // Either is enough — a photo on its own is a perfectly good message.
            'body' => ['nullable', 'string', 'max:5000'],
            'files' => ['nullable', 'array', 'max:10'],
            'files.*' => ['file', 'mimes:jpg,jpeg,png,webp,gif,pdf', 'max:65536'],
        ]);

        $body = trim((string) ($data['body'] ?? ''));
        $hasFiles = $request->hasFile('files');

        if ($body === '' && ! $hasFiles) {
            return back()->withErrors(['body' => 'Type a message or attach a photo.'])->withInput();
        }

        $participants = $this->participants($order);

        $message = Message::create([
            'production_order_id' => $order->id,
            'sender_id' => $me->id,
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
        $order = $file->message?->order;
        abort_unless($order && Message::canAccess($request->user(), $order), 403);
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
        $ids = $order->tasks()->whereNotNull('assigned_to')->pluck('assigned_to');

        return User::where('is_active', true)
            ->where(function ($q) use ($ids, $order) {
                $q->whereIn('id', $ids)
                    ->orWhere('id', $order->created_by)
                    ->orWhereIn('job_role', [User::ROLE_LEADER, User::ROLE_SUPER_ADMIN])
                    ->orWhereRaw('LOWER(TRIM(job_role)) = ?', ['mover']);
            })
            ->orderBy('name')
            ->get();
    }
}
