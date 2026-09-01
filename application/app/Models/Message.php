<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One message in a job order's conversation. The thread belongs to the ORDER,
 * and everyone connected to that order shares it — the account officer who owns
 * it, leaders/admin, and anyone assigned to one of its tasks.
 */
class Message extends Model
{
    protected $fillable = ['production_order_id', 'inquiry_id', 'sender_id', 'sender_name', 'body'];

    public function sender()
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    /**
     * Who to answer. On a shared login that is the name the person typed —
     * the account it came through is noise on the thread, and the sender_id is
     * still on the row for anyone who needs it.
     */
    public function senderLabel(): string
    {
        return filled($this->sender_name)
            ? $this->sender_name
            : ($this->sender?->name ?? 'Someone');
    }

    public function order()
    {
        return $this->belongsTo(ProductionOrder::class, 'production_order_id');
    }

    /**
     * The layout this was said about, when it was said before the job order
     * existed. Kept even after the order is written, so the thread can still
     * say which part of it happened at the drawing stage.
     */
    public function inquiry()
    {
        return $this->belongsTo(Inquiry::class, 'inquiry_id');
    }

    /**
     * Who may read and add to a layout's thread.
     *
     * The officer whose inquiry it is, the artist drawing it, and the leaders
     * — the same people who can already open the layout itself. Nobody else
     * has any business in it, and there is no job order yet to inherit from.
     */
    public static function canAccessInquiry(User $user, Inquiry $inquiry): bool
    {
        return $user->isLeader()
            || $inquiry->created_by === $user->id
            || $inquiry->layout_artist_id === $user->id
            || ($user->leadsTeam() && $inquiry->team === $user->team);
    }

    /**
     * Hand the layout's conversation to the job order it became.
     *
     * Called once, when the order is written. The messages keep their inquiry
     * so the history is not rewritten, and gain the order — which is all the
     * existing thread needs to start showing them.
     */
    public static function carryLayoutThreadTo(Inquiry $inquiry, ProductionOrder $order): int
    {
        return self::where('inquiry_id', $inquiry->id)
            ->whereNull('production_order_id')
            ->update(['production_order_id' => $order->id]);
    }

    /** The people @mentioned in this message. */
    public function mentions()
    {
        return $this->belongsToMany(User::class, 'message_mentions')->withTimestamps();
    }

    /** Photos / files sent with this message. */
    public function files()
    {
        return $this->hasMany(MessageFile::class);
    }

    /**
     * One line describing this message, for the inbox list. A message can be a
     * photo with nothing typed, so falling back to the body alone would leave
     * the row reading "You:" and then nothing.
     */
    public function preview(): string
    {
        if (filled($this->body)) {
            return $this->body;
        }

        $files = $this->files;
        $count = $files->count();

        if ($count === 0) {
            return '';
        }

        $allImages = $files->every(fn ($f) => $f->isImage());

        if ($allImages) {
            return $count === 1 ? '📷 Photo' : "📷 {$count} photos";
        }

        return $count === 1 ? '📎 File' : "📎 {$count} files";
    }

    /**
     * Which of $candidates are @mentioned in $body. Longest names are matched
     * first so "@Maam Carla" doesn't get claimed by a "@Maam".
     *
     * @return array<int> user ids
     */
    public static function detectMentions(string $body, $candidates): array
    {
        $found = [];
        $remaining = $body;

        $ordered = collect($candidates)->sortByDesc(fn ($u) => mb_strlen((string) $u->name));

        foreach ($ordered as $user) {
            $needle = '@'.$user->name;

            if (mb_stripos($remaining, $needle) !== false) {
                $found[] = $user->id;
                // Consume it so a shorter name inside it can't match too.
                $remaining = str_ireplace($needle, '', $remaining);
            }
        }

        return array_values(array_unique($found));
    }

    /**
     * The body with @mentions wrapped for display. Escapes first, so a message
     * can never inject markup.
     */
    public function bodyWithMentions(): string
    {
        $html = e($this->body);

        foreach ($this->mentions as $user) {
            $html = str_ireplace(
                e('@'.$user->name),
                '<span class="mention">@'.e($user->name).'</span>',
                $html
            );
        }

        return $html;
    }

    /**
     * Who may read and post in an order's thread. Same rule the job-order files
     * already use, so the chat never widens access to an order.
     */
    public static function canAccess(User $user, ProductionOrder $order): bool
    {
        // A job becomes the mover's when it reaches the printer — before that
        // it is still the account officer's and the artist's. From then on she
        // reads the whole thread, background included.
        if ($user->isMover()) {
            return $order->loadMissing('tasks')->reachedTheFloorAt() !== null;
        }

        return $user->isLeader()
            || ($user->isSales() && $order->created_by === $user->id)
            || $order->tasks()->where('assigned_to', $user->id)->exists();
    }

    /** The orders whose threads this person is part of. */
    public static function accessibleOrderIds(User $user): \Illuminate\Support\Collection
    {
        return ProductionOrder::query()
            // A job becomes the mover's when it reaches the printer. This is
            // the one place that decides it, so her unread badge counts the
            // same threads her inbox lists — otherwise the badge promises
            // messages on jobs she cannot open.
            ->when($user->isMover(), fn ($q) => $q->whereHas(
                'tasks',
                fn ($t) => $t->where('department', ProductionOrder::MOVER_FIRST_STEP)
                    ->whereNotNull('released_at')
            ))
            // The raw materials desk supplies every job on the floor, so it
            // needs the whole inbox: a question about fabric on a job order
            // they are not assigned to is still theirs to answer, and they
            // were only seeing threads on orders they happened to hold a step
            // on.
            ->when(! $user->isLeader() && ! $user->isMover() && ! $user->canManageInventory(),
                function ($q) use ($user) {
                $q->where(function ($w) use ($user) {
                    $w->where('created_by', $user->id)
                        ->orWhereHas('tasks', fn ($t) => $t->where('assigned_to', $user->id));
                });
            })
            ->pluck('id');
    }

    /** Unread messages for this person in one order (their own don't count). */
    public static function unreadInOrder(User $user, int $orderId): int
    {
        $lastRead = MessageRead::where('user_id', $user->id)
            ->where('production_order_id', $orderId)
            ->value('last_read_at');

        return self::where('production_order_id', $orderId)
            ->where('sender_id', '!=', $user->id)
            ->when($lastRead, fn ($q) => $q->where('created_at', '>', $lastRead))
            ->count();
    }

    /**
     * Unread counts for a page of threads, keyed by order id.
     *
     * The inbox asks this of every thread it lists. Asked one thread at a time
     * that is two queries a row — find when they last read it, then count what
     * has arrived since. The join answers the whole page at once, and each
     * thread keeps its own read mark rather than sharing one cut-off.
     *
     * @param  array<int, int>  $orderIds
     * @return \Illuminate\Support\Collection<int, int>
     */
    public static function unreadCountsForOrders(User $user, array $orderIds): \Illuminate\Support\Collection
    {
        if ($orderIds === []) {
            return collect();
        }

        return self::query()
            ->from('messages as m')
            ->leftJoin('message_reads as r', function ($join) use ($user) {
                $join->on('r.production_order_id', '=', 'm.production_order_id')
                    ->where('r.user_id', '=', $user->id);
            })
            ->whereIn('m.production_order_id', $orderIds)
            ->where('m.sender_id', '!=', $user->id)
            ->where(fn ($q) => $q
                ->whereNull('r.last_read_at')
                ->orWhereColumn('m.created_at', '>', 'r.last_read_at'))
            ->groupBy('m.production_order_id')
            ->selectRaw('m.production_order_id, COUNT(*) as unread')
            ->pluck('unread', 'm.production_order_id');
    }

    /**
     * Total unread across every thread this person can see (nav badge).
     *
     * Job orders and layouts in one count, and one query for the counting.
     * This badge is rendered on EVERY page, so what it costs is paid on every
     * page — reading the messages back into PHP to compare them, as this used
     * to, was the expensive way to ask a question the database can answer.
     */
    public static function unreadFor(int $userId): int
    {
        $user = User::find($userId);
        if (! $user) {
            return 0;
        }

        return (int) self::query()
            ->from('messages as m')
            ->leftJoin('message_reads as ro', function ($join) use ($user) {
                $join->on('ro.production_order_id', '=', 'm.production_order_id')
                    ->where('ro.user_id', '=', $user->id);
            })
            ->leftJoin('message_reads as ri', function ($join) use ($user) {
                $join->on('ri.inquiry_id', '=', 'm.inquiry_id')
                    ->where('ri.user_id', '=', $user->id);
            })
            ->where('m.sender_id', '!=', $user->id)
            ->where(function ($q) use ($user) {
                // On a job order she is part of, unread since she last looked.
                $q->where(fn ($order) => $order
                    ->whereIn('m.production_order_id', self::accessibleOrderIds($user))
                    ->where(fn ($seen) => $seen
                        ->whereNull('ro.last_read_at')
                        ->orWhereColumn('m.created_at', '>', 'ro.last_read_at')))
                    // …or on a layout that has no job order yet. Once it has
                    // one the message is counted on the order instead, never
                    // on both.
                    ->orWhere(fn ($layout) => $layout
                        ->whereNull('m.production_order_id')
                        ->whereIn('m.inquiry_id', self::accessibleInquiryQuery($user))
                        ->where(fn ($seen) => $seen
                            ->whereNull('ri.last_read_at')
                            ->orWhereColumn('m.created_at', '>', 'ri.last_read_at')));
            })
            ->count();
    }

    /**
     * Unread per LAYOUT thread, for the inbox rows that have no job order yet.
     *
     * Same shape as unreadCountsForOrders, keyed on the inquiry instead. A
     * layout message raised no badge at all before this, so an artist could be
     * waiting on an answer nobody knew had been asked for.
     */
    public static function unreadCountsForInquiries(User $user, array $inquiryIds): \Illuminate\Support\Collection
    {
        if ($inquiryIds === []) {
            return collect();
        }

        return self::query()
            ->from('messages as m')
            ->leftJoin('message_reads as r', function ($join) use ($user) {
                $join->on('r.inquiry_id', '=', 'm.inquiry_id')
                    ->where('r.user_id', '=', $user->id);
            })
            ->whereIn('m.inquiry_id', $inquiryIds)
            ->where('m.sender_id', '!=', $user->id)
            ->where(fn ($q) => $q
                ->whereNull('r.last_read_at')
                ->orWhereColumn('m.created_at', '>', 'r.last_read_at'))
            ->groupBy('m.inquiry_id')
            ->selectRaw('m.inquiry_id, COUNT(*) as unread')
            ->pluck('unread', 'm.inquiry_id');
    }

    /**
     * The layouts whose threads this person is part of — the same rule the
     * thread page enforces, in one query so the badge and the inbox agree.
     */
    public static function accessibleInquiryIds(User $user): \Illuminate\Support\Collection
    {
        return self::accessibleInquiryQuery($user)->pluck('id');
    }

    /** The same rule as a query, for folding into a bigger one. */
    public static function accessibleInquiryQuery(User $user)
    {
        return Inquiry::query()
            ->select('id')
            ->whereNull('production_order_id')
            ->when(! $user->isLeader(), fn ($q) => $q->where(fn ($w) => $w
                ->where('created_by', $user->id)
                ->orWhere('layout_artist_id', $user->id)
                ->orWhere(fn ($team) => $user->leadsTeam()
                    ? $team->where('team', $user->team)
                    : $team->whereRaw('1 = 0'))));
    }

    /** Mark a layout's thread as read up to now. */
    public static function markInquiryRead(User $user, int $inquiryId): void
    {
        MessageRead::updateOrCreate(
            ['user_id' => $user->id, 'inquiry_id' => $inquiryId],
            ['last_read_at' => now()],
        );
    }

    /** Mark an order's thread as read up to now. */
    public static function markRead(User $user, int $orderId): void
    {
        MessageRead::updateOrCreate(
            ['user_id' => $user->id, 'production_order_id' => $orderId],
            ['last_read_at' => now()],
        );
    }
}
