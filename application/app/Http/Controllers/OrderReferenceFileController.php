<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AuthorizesOrderAccess;
use App\Models\ProductionOrder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Client-reference files attached to a job order (pegs, logos, and the ChatGPT
 * design "output" the artist works from). Split out of ProductionOrderController.
 */
class OrderReferenceFileController extends Controller
{
    use AuthorizesOrderAccess;

    /** Who may see a job order reference: the officer who owns it, leaders, or an assigned artist. */
    private function assertCanSeeReference(\App\Models\JobOrderFile $file): void
    {
        $user = auth()->user();
        $order = $file->jobOrder->order;

        $allowed = $user->isLeader()
            || ($user->isSales() && $order->created_by === $user->id)
            || $order->tasks()->where('assigned_to', $user->id)->exists();

        abort_unless($allowed, 403);
        abort_unless(\Illuminate\Support\Facades\Storage::disk('local')->exists($file->path), 404);
    }

    public function viewReferenceFile(\App\Models\JobOrderFile $file)
    {
        $this->assertCanSeeReference($file);

        return \Illuminate\Support\Facades\Storage::disk('local')->response($file->path, $file->original_name);
    }

    public function downloadReferenceFile(\App\Models\JobOrderFile $file)
    {
        $this->assertCanSeeReference($file);

        return \Illuminate\Support\Facades\Storage::disk('local')->download($file->path, $file->original_name);
    }

    public function uploadReferenceFile(Request $request, ProductionOrder $order): RedirectResponse
    {
        $this->assertOrderVisible($order);
        $order->load('jobOrder');
        abort_unless($order->jobOrder, 404);

        $data = $request->validate([
            'reference_files' => ['required', 'array'],
            'reference_files.*' => ['file', 'mimes:jpg,jpeg,png,webp,gif,pdf,ai,psd,eps,cdr,zip', 'max:512000'],
            // "output" = the design saved from ChatGPT (what the artist works from).
            'kind' => ['nullable', 'in:peg,logo,output'],
            // What the officer wants to say about them. A photo of a logo with
            // no word attached is a photo the artist has to guess at — is this
            // the logo, the placement, the colour, the thing to avoid?
            'note' => ['nullable', 'string', 'max:2000'],
        ], [
            'reference_files.required' => 'Choose at least one file to upload.',
        ]);

        foreach ($request->file('reference_files') as $file) {
            $order->jobOrder->referenceFiles()->create([
                'path' => $file->store('job-order-refs', 'local'),
                'original_name' => $file->getClientOriginalName(),
                'kind' => $data['kind'] ?? null,
                // The same message on every file of one upload: one batch,
                // one thing the officer was saying about it.
                'note' => filled($data['note'] ?? null) ? trim($data['note']) : null,
                'mime' => $file->getClientMimeType(),
                'size' => $file->getSize(),
                'uploaded_by' => $request->user()->id,
            ]);
        }

        $count = count($request->file('reference_files'));

        // A late file nobody is told about is a file nobody opens.
        //
        // The client keeps sending things after the work has started, and the
        // artist is head down in a pack they were handed days ago. Told once
        // each, however many files landed at once, and only once the pack has
        // actually gone out — before that they have not been given anything
        // to be interrupted about.
        $told = $order->jobOrder->sent_to_artist_at
            ? $this->tellTheArtists($order, $count)
            : collect();

        return back()->with('success', match (true) {
            ($data['kind'] ?? null) === 'output' => 'Design uploaded — this is what the artist will work from.',
            $told->isNotEmpty() => \Illuminate\Support\Str::plural('File', $count).' sent to '.$told->implode(' and ').'.',
            default => 'File uploaded.',
        });
    }

    /**
     * Tell the artists on this order that files have arrived, once each.
     *
     * Whoever is actually holding an artist step, not every artist who ever
     * touched it: a finished Layout is not somebody who needs to know a photo
     * landed for the mockup. Returns the names, so the officer is told who was
     * told rather than a bare "File uploaded."
     */
    private function tellTheArtists(ProductionOrder $order, int $count): \Illuminate\Support\Collection
    {
        $artists = $order->tasks()
            ->where('team', \App\Models\User::JOB_ARTIST)
            ->whereNotNull('assigned_to')
            ->whereNotIn('status', ['complete', 'cancelled'])
            ->with('assignee')
            ->get()
            ->pluck('assignee')
            ->filter()
            ->unique('id');

        foreach ($artists as $artist) {
            \App\Models\AppNotification::toUser(
                $artist->id,
                '📎 '.$count.' more '.\Illuminate\Support\Str::plural('file', $count).' for a job you are on',
                $order->order_number.' — '.$order->clientName().'. Sent by the account officer after the pack went out.',
                route('tasks.mine'),
            );
        }

        return $artists->pluck('name')->values();
    }

    /** Mark an already-uploaded file as the ChatGPT design the artist works from. */
    public function markReferenceKind(Request $request, \App\Models\JobOrderFile $file): RedirectResponse
    {
        $order = $file->jobOrder->order;
        $this->assertOrderVisible($order);

        $data = $request->validate([
            'kind' => ['required', 'in:peg,logo,output'],
        ]);

        $file->update(['kind' => $data['kind']]);

        return back()->with('success', $data['kind'] === 'output'
            ? 'Set as the design the artist works from.'
            : 'File updated.');
    }

    public function deleteReferenceFile(\App\Models\JobOrderFile $file): RedirectResponse
    {
        $order = $file->jobOrder->order;
        $this->assertOrderVisible($order);

        \Illuminate\Support\Facades\Storage::disk('local')->delete($file->path);
        $file->delete();

        return back()->with('success', 'Reference file removed.');
    }
}
