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
    protected $fillable = ['production_order_id', 'sender_id', 'sender_name', 'body'];

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
            // The mover follows every job once it reaches the floor; the
            // printer check happens where the list is built, since it reads the
            // task timestamps.
            ->when(! $user->isLeader() && ! $user->isMover(), function ($q) use ($user) {
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

    /** Total unread across every thread this person can see (nav badge). */
    public static function unreadFor(int $userId): int
    {
        $user = User::find($userId);
        if (! $user) {
            return 0;
        }

        $reads = MessageRead::where('user_id', $user->id)
            ->pluck('last_read_at', 'production_order_id');

        return self::whereIn('production_order_id', self::accessibleOrderIds($user))
            ->where('sender_id', '!=', $user->id)
            ->get(['production_order_id', 'created_at'])
            ->filter(function ($m) use ($reads) {
                $seen = $reads[$m->production_order_id] ?? null;

                return $seen === null || $m->created_at > $seen;
            })
            ->count();
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
