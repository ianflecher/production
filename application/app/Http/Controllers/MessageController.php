<?php

namespace App\Http\Controllers;

use App\Models\AppNotification;
use App\Models\Message;
use App\Models\ProductionOrder;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Direct messages between staff accounts. A message can carry a job order,
 * which shows as a card in the thread.
 */
class MessageController extends Controller
{
    /** Inbox: one row per person you have talked to, newest first. */
    public function index(Request $request): View
    {
        $me = $request->user()->id;

        // The other person in each conversation, with the last message and how
        // many of theirs are still unread.
        $latest = Message::involving($me)
            ->with(['sender', 'recipient', 'order'])
            ->orderByDesc('id')
            ->get()
            ->groupBy(fn ($m) => $m->sender_id === $me ? $m->recipient_id : $m->sender_id);

        $conversations = $latest->map(function ($messages, $otherId) use ($me) {
            $last = $messages->first();

            return [
                'user' => $last->sender_id === $me ? $last->recipient : $last->sender,
                'last' => $last,
                'unread' => $messages->where('recipient_id', $me)->whereNull('read_at')->count(),
            ];
        })->filter(fn ($c) => $c['user'] !== null)
            ->sortByDesc(fn ($c) => $c['last']->id)
            ->values();

        return view('messages.index', [
            'conversations' => $conversations,
            'people' => $this->addressBook($request->user()),
        ]);
    }

    /** One conversation. Opening it marks their messages read. */
    public function show(Request $request, User $user): View
    {
        $me = $request->user();
        abort_if($user->id === $me->id, 404);

        Message::where('sender_id', $user->id)
            ->where('recipient_id', $me->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        $messages = Message::conversation($me->id, $user->id)
            ->with(['sender', 'order.client'])
            ->orderBy('id')
            ->get();

        return view('messages.show', [
            'other' => $user,
            'messages' => $messages,
            'orders' => $this->attachableOrders($me),
            'people' => $this->addressBook($me),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $me = $request->user();

        $data = $request->validate([
            'recipient_id' => ['required', 'integer', 'exists:users,id'],
            'body' => ['nullable', 'string', 'max:5000'],
            'production_order_id' => ['nullable', 'integer', 'exists:production_orders,id'],
        ]);

        abort_if((int) $data['recipient_id'] === $me->id, 422);

        // A message needs to say something or carry an order.
        if (blank($data['body'] ?? null) && blank($data['production_order_id'] ?? null)) {
            return back()->withErrors(['body' => 'Type a message or attach a job order.'])->withInput();
        }

        // You can only send an order you are allowed to see yourself.
        $orderId = $data['production_order_id'] ?? null;
        if ($orderId && ! $this->attachableOrders($me)->contains('id', (int) $orderId)) {
            abort(403);
        }

        $message = Message::create([
            'sender_id' => $me->id,
            'recipient_id' => $data['recipient_id'],
            'body' => filled($data['body'] ?? null) ? trim($data['body']) : null,
            'production_order_id' => $orderId,
        ]);

        // Reuse the existing desktop-alert pipeline.
        $preview = $message->body
            ? \Illuminate\Support\Str::limit($message->body, 80)
            : 'Sent you a job order.';

        AppNotification::toUser(
            (int) $data['recipient_id'],
            'Message from '.$me->name,
            $preview,
            route('messages.show', $me)
        );

        return redirect()->route('messages.show', $data['recipient_id'])->withFragment('end');
    }

    /** Unread count for the page poll (drives the nav badge). */
    public function unread(Request $request)
    {
        return response()->json(['unread' => Message::unreadFor($request->user()->id)]);
    }

    /** Everyone else who can be messaged. */
    private function addressBook(User $me)
    {
        return User::where('is_active', true)
            ->where('id', '!=', $me->id)
            ->orderBy('name')
            ->get(['id', 'name', 'job_role']);
    }

    /**
     * Orders this person may attach — the same ones they are allowed to open:
     * leaders see everything, officers their own, agents the ones they work on.
     */
    private function attachableOrders(User $me)
    {
        return ProductionOrder::query()
            ->when(! $me->isLeader(), function ($q) use ($me) {
                $q->where(function ($w) use ($me) {
                    $w->where('created_by', $me->id)
                        ->orWhereHas('tasks', fn ($t) => $t->where('assigned_to', $me->id));
                });
            })
            ->orderByDesc('id')
            ->limit(100)
            ->get(['id', 'order_number', 'customer_name']);
    }
}
