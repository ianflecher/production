<?php

namespace App\Http\Controllers;

use App\Models\AppNotification;
use App\Models\Inquiry;
use App\Models\Message;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Talking about a layout before there is a job order to talk about it on.
 *
 * The artist and the officer have most of their conversation while the drawing
 * is being made — which is before the order exists, so it had nowhere to live
 * and went to Viber, where it stopped being part of the job. This is the same
 * thread, started early. Once the job order is written these messages are
 * stamped with it and become the order's thread; nothing has to be repeated.
 */
class InquiryMessageController extends Controller
{
    public function store(Request $request, Inquiry $inquiry): RedirectResponse
    {
        $me = $request->user();
        abort_unless(Message::canAccessInquiry($me, $inquiry), 403);

        $data = $request->validate([
            // A photo on its own is a message: the artist sends a screenshot
            // and asks nothing, and that is the whole point of sending it.
            'body' => ['nullable', 'string', 'max:5000'],
            'files' => ['nullable', 'array'],
            'files.*' => ['file', 'mimes:jpg,jpeg,png,webp,gif,pdf,ai,psd,eps,cdr,zip', 'max:65536'],
        ]);

        $body = trim((string) ($data['body'] ?? ''));
        $hasFiles = $request->hasFile('files');

        if ($body === '' && ! $hasFiles) {
            return back()->withErrors(['body' => 'Type a message or attach a photo.'])->withInput();
        }

        $message = Message::create([
            'inquiry_id' => $inquiry->id,
            // No order yet — that is the whole point. It is filled in when the
            // job order is written, by Message::carryLayoutThreadTo().
            'production_order_id' => $inquiry->production_order_id,
            'sender_id' => $me->id,
            'sender_name' => $me->name,
            'body' => $body !== '' ? $body : null,
        ]);

        foreach ($request->file('files', []) as $file) {
            $message->files()->create([
                'path' => $file->store('message-files', 'local'),
                'original_name' => $file->getClientOriginalName(),
                'mime' => $file->getClientMimeType(),
                'size' => $file->getSize(),
            ]);
        }

        $this->tellTheOtherSide($inquiry, $me->id, $message->preview());

        return back()->with('success', 'Sent.');
    }

    /**
     * The layout has two sides — the officer who asked and the artist drawing
     * it. Whichever one did not type this should hear about it.
     */
    private function tellTheOtherSide(Inquiry $inquiry, int $senderId, string $body): void
    {
        $recipients = array_filter([
            $inquiry->created_by,
            $inquiry->layout_artist_id,
        ], fn ($id) => $id && $id !== $senderId);

        foreach (array_unique($recipients) as $userId) {
            AppNotification::toUser(
                $userId,
                '💬 About the layout',
                $inquiry->client?->fullName().' — '.\Illuminate\Support\Str::limit($body, 90),
                $inquiry->layout_artist_id === $userId
                    ? route('inquiries.layouts')
                    : route('inquiries.layout', $inquiry)
            );
        }
    }
}
