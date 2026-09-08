<?php

namespace App\Http\Controllers;

use App\Models\AppNotification;
use App\Models\Inquiry;
use App\Models\InquiryDesign;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * The designs under one client's brief.
 *
 * A client who wants six designs is ordinary, and they are not all one
 * person's work: five can be Cristal's and the sixth Mick's. Each is drawn,
 * handed back, and answered on its own, so the client approving five and
 * sending one back is something the shop writes down rather than argues about.
 *
 * The brief above them stays on the enquiry - it is the same brief for all of
 * them - and the job order is still opened by ONE thing: every design in the
 * set approved.
 */
class InquiryDesignController extends Controller
{
    /** Only account officers take enquiries; leaders oversee them. */
    private function assertAccess(Request $request): void
    {
        abort_unless($request->user()->isSales() || $request->user()->isLeader(), 403);
    }

    private function assertMine(Request $request, Inquiry $inquiry): void
    {
        $user = $request->user();

        if ($user->isLeader() || $inquiry->created_by === $user->id) {
            return;
        }

        abort_unless($user->leadsTeam() && $inquiry->team === $user->team, 403);
    }

    /** The design must belong to the enquiry in the URL, not just exist. */
    private function designOf(Inquiry $inquiry, int $designId): InquiryDesign
    {
        return $inquiry->designs()->whereKey($designId)->firstOrFail();
    }

    /**
     * Add a design to the brief.
     *
     * Each one is given an artist as it is added, so the officer leaves this
     * page knowing whose desk every design landed on. Left unnamed, whoever is
     * in today takes it.
     */
    public function store(Request $request, Inquiry $inquiry): RedirectResponse
    {
        $this->assertAccess($request);
        $this->assertMine($request, $inquiry);

        $data = $request->validate([
            'label' => ['nullable', 'string', 'max:120'],
            'artist_id' => ['nullable', 'integer', 'exists:users,id'],
            // Six at a time rather than six clicks: a kit is listed in one go.
            'how_many' => ['nullable', 'integer', 'min:1', 'max:20'],
        ]);

        $artist = isset($data['artist_id'])
            ? User::find($data['artist_id'])
            : \App\Services\StaffAssigner::next(User::JOB_ARTIST);

        $next = (int) $inquiry->designs()->max('position');
        $next = $inquiry->designs()->count() ? $next + 1 : 0;

        $howMany = (int) ($data['how_many'] ?? 1);

        for ($i = 0; $i < $howMany; $i++) {
            $inquiry->designs()->create([
                'label' => $howMany > 1 && filled($data['label'] ?? null)
                    ? $data['label'].' '.($i + 1)
                    : ($data['label'] ?? null),
                'position' => $next + $i,
                'artist_id' => $artist?->id,
                'status' => InquiryDesign::STATUS_WITH_ARTIST,
                // Already sent? Then this one is on their desk from now.
                'sent_at' => $inquiry->layout_sent_at ? now() : null,
            ]);
        }

        $inquiry->syncLayoutStatus();

        // Added after the brief went out, it has to be announced - nothing else
        // would tell the artist a seventh design just landed.
        if ($inquiry->layout_sent_at && $artist) {
            AppNotification::toUser($artist->id,
                '🎨 Another design to draw',
                $inquiry->client->fullName().' — '.$howMany.' more on the same brief.',
                route('inquiries.layouts'));
        }

        return back()->with('success', $howMany > 1
            ? $howMany.' designs added'.($artist ? ' for '.$artist->name : '').'.'
            : 'Design added'.($artist ? ' for '.$artist->name : '').'.');
    }

    /** Take one off the list. Only while nothing has been drawn on it. */
    public function destroy(Request $request, Inquiry $inquiry, int $design): RedirectResponse
    {
        $this->assertAccess($request);
        $this->assertMine($request, $inquiry);

        $design = $this->designOf($inquiry, $design);

        if ($design->drawings()->isNotEmpty()) {
            return back()->withErrors(['designs' =>
                $design->name().' has already been drawn, so it cannot be taken off the brief.']);
        }

        $design->delete();
        $inquiry->syncLayoutStatus();

        return back()->with('success', 'Design removed.');
    }

    /**
     * Hand one design to a different artist.
     *
     * Per design, not per brief: an artist who goes home sick takes only their
     * share of the set with them, and the officer moves those without
     * disturbing the ones already being drawn by somebody else.
     */
    public function assign(Request $request, Inquiry $inquiry, int $design): RedirectResponse
    {
        $this->assertAccess($request);
        $this->assertMine($request, $inquiry);

        // The officer arranges the set while the brief is still a draft. Once
        // it has gone out, moving work somebody has already started is the
        // leader's call - the same rule the whole-brief handover has always
        // had, kept per design.
        abort_unless(! $inquiry->layout_sent_at || $request->user()->isLeader(), 403);

        $data = $request->validate(
            ['artist_id' => ['required', 'integer', 'exists:users,id']],
            ['artist_id.required' => 'Choose who is drawing it.']
        );

        $design = $this->designOf($inquiry, $design);
        $artist = User::findOrFail($data['artist_id']);

        abort_unless($artist->isArtist(), 422);

        $design->update(['artist_id' => $artist->id]);

        if ($inquiry->layout_sent_at) {
            AppNotification::toUser($artist->id,
                '🎨 A design was moved to you',
                $inquiry->client->fullName().' — '.$design->name().'.',
                route('inquiries.layouts'));
        }

        return back()->with('success', $design->name().' is now '.$artist->name."'s.");
    }

    /** The artist hands back ONE design; the rest stay on their desk. */
    public function submit(Request $request, int $design): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->isArtist() || $user->isLeader(), 403);

        $design = InquiryDesign::with('inquiry.client')->findOrFail($design);

        abort_unless($design->artist_id === $user->id || $user->isLeader(), 403);
        // A design already handed back can be drawn over: the client asks the
        // artist directly as often as they ask the officer.
        abort_unless($design->withArtist() || $design->submitted(), 403);

        $request->validate([
            'files' => ['required', 'array'],
            'files.*' => ['file', 'mimes:jpg,jpeg,png,webp,gif,pdf,ai,psd,eps,cdr,zip', 'max:512000'],
        ], ['files.required' => 'Attach the design before handing it back.']);

        $files = $design->files ?? [];

        foreach ($request->file('files') as $file) {
            $files[] = [
                'path' => $file->store('inquiry-layouts', 'local'),
                'original_name' => $file->getClientOriginalName(),
                'mime' => $file->getClientMimeType(),
                'size' => $file->getSize(),
                'uploaded_by' => $user->id,
                'kind' => 'layout',
            ];
        }

        $design->update([
            'files' => $files,
            'status' => InquiryDesign::STATUS_SUBMITTED,
            'submitted_at' => now(),
            'revision_note' => null,
        ]);

        $inquiry = $design->inquiry;
        $inquiry->syncLayoutStatus();

        $left = $inquiry->designsOutstanding()->count();

        AppNotification::toUser($inquiry->created_by,
            '🎨 A design is ready for the client',
            $inquiry->client->fullName().' — '.$user->name.' finished '.$design->name()
                .($left ? ' ('.$left.' still open)' : ' — that is the last one.'),
            route('inquiries.layout', $inquiry));

        return back()->with('success', $design->name().' handed back.');
    }

    /** The client said yes to this one. */
    public function approve(Request $request, Inquiry $inquiry, int $design): RedirectResponse
    {
        $this->assertAccess($request);
        $this->assertMine($request, $inquiry);

        $design = $this->designOf($inquiry, $design);
        abort_unless($design->submitted(), 403);

        $design->update([
            'status' => InquiryDesign::STATUS_APPROVED,
            'approved_at' => now(),
        ]);

        $inquiry->syncLayoutStatus();

        // The job order opens on the whole set, never on a partial yes.
        if ($inquiry->layoutStatus() === Inquiry::LAYOUT_APPROVED) {
            return redirect()->route('orders.create', ['inquiry' => $inquiry->id])
                ->with('success', 'Every design approved. Write the job order.');
        }

        return back()->with('success', $design->name().' approved. '
            .$inquiry->designsOutstanding()->count().' still to answer.');
    }

    /** The client wants this one changed. Back to whoever drew it. */
    public function revise(Request $request, Inquiry $inquiry, int $design): RedirectResponse
    {
        $this->assertAccess($request);
        $this->assertMine($request, $inquiry);

        $design = $this->designOf($inquiry, $design);
        abort_unless($design->submitted(), 403);

        // Three rounds per design is what the client is promised. A leader can
        // still give one away - that call is theirs, not the form's.
        if ($design->revisionsUsedUp() && ! $request->user()->isLeader()) {
            return back()->withErrors(['designs' =>
                $design->name().' has already had its '.InquiryDesign::REVISION_LIMIT
                .' revisions. A leader can send it back again.']);
        }

        $data = $request->validate(
            ['revision_note' => ['required', 'string', 'max:2000']],
            ['revision_note.required' => 'Say what the client wants changed.']
        );

        $design->update([
            'status' => InquiryDesign::STATUS_WITH_ARTIST,
            'revision_note' => $data['revision_note'],
            'revision_count' => (int) $design->revision_count + 1,
            'submitted_at' => null,
        ]);

        $inquiry->syncLayoutStatus();

        if ($design->artist_id) {
            AppNotification::toUser($design->artist_id,
                '✏️ A design came back',
                $inquiry->client->fullName().' — '.$design->name().': '.$data['revision_note'],
                route('inquiries.layouts'));
        }

        return back()->with('success', $design->name().' sent back to '
            .($design->artist?->name ?? 'the artists').'.');
    }

    /** Serve one design's file from private storage. */
    public function file(Request $request, int $design, int $index)
    {
        $user = $request->user();
        $design = InquiryDesign::with('inquiry')->findOrFail($design);

        // The people with a reason to open it: whoever drew it, the officer
        // who owns the enquiry, and the leaders.
        abort_unless(
            $design->artist_id === $user->id
                || $design->inquiry->created_by === $user->id
                || $user->isLeader()
                || $user->isSales(),
            403
        );

        $file = ($design->files ?? [])[$index] ?? null;
        $path = $file['path'] ?? null;

        abort_unless($path && Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response($path, $file['original_name'] ?? basename($path));
    }
}
