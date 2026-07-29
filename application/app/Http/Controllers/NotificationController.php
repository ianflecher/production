<?php

namespace App\Http\Controllers;

use App\Models\AppNotification;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    /**
     * Return the signed-in user's pending desktop alerts and immediately mark
     * them delivered, so each one pops up exactly once.
     */
    public function poll(Request $request)
    {
        $pending = AppNotification::pendingFor($request->user());

        if ($pending->isNotEmpty()) {
            AppNotification::whereIn('id', $pending->pluck('id'))->update(['delivered_at' => now()]);
        }

        return response()->json([
            'notifications' => $pending->map(fn ($n) => [
                'id' => $n->id,
                'title' => $n->title,
                'body' => $n->body,
                'url' => $n->url,
            ])->values(),
        ]);
    }

    /** Store a browser's Web Push subscription so we can reach it when closed. */
    public function subscribe(Request $request)
    {
        $data = $request->validate([
            'endpoint' => ['required', 'string'],
            'keys.p256dh' => ['required', 'string'],
            'keys.auth' => ['required', 'string'],
        ]);

        \App\Models\PushSubscription::updateOrCreate(
            ['endpoint_hash' => hash('sha256', $data['endpoint'])],
            [
                'user_id' => $request->user()->id,
                'endpoint' => $data['endpoint'],
                'public_key' => $data['keys']['p256dh'],
                'auth_token' => $data['keys']['auth'],
            ],
        );

        return response()->json(['ok' => true]);
    }

    public function unsubscribe(Request $request)
    {
        $endpoint = $request->input('endpoint');

        if ($endpoint) {
            \App\Models\PushSubscription::where('endpoint_hash', hash('sha256', $endpoint))
                ->where('user_id', $request->user()->id)
                ->delete();
        }

        return response()->json(['ok' => true]);
    }
}
