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
            'description' => ['nullable', 'string', 'max:2000'],
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
                'description' => $data['description'] ?? null,
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

    /** Save instructions for one design while the officer is preparing the brief. */
    public function updateDescription(Request $request, Inquiry $inquiry, int $design): RedirectResponse
    {
        $this->assertAccess($request);
        $this->assertMine($request, $inquiry);

        abort_if($inquiry->layout_sent_at, 422, 'Design instructions are locked after the brief is sent to the artist.');

        $data = $request->validate([
            'description' => ['nullable', 'string', 'max:2000'],
        ]);

        $this->designOf($inquiry, $design)->update([
            'description' => filled($data['description'] ?? null) ? trim($data['description']) : null,
        ]);

        return back()->with('success', 'Design description saved.');
    }

    /**
     * Rename a design.
     *
     * The name was settled when the design was created and never again, so a
     * typo or a wrong number - EVO COTTON SHIRT 3 where the set runs to two -
     * could only be fixed by deleting the design, which is itself refused once
     * anything has been drawn on it.
     *
     * Allowed after the brief has gone out, unlike the description beside it.
     * The description is the instruction the artist is drawing to and changing
     * it under them would be moving the goalposts; the name is only what the
     * thing is called on a list, and a wrong one is most worth fixing exactly
     * when somebody is looking at it.
     */
    public function rename(Request $request, Inquiry $inquiry, int $design): RedirectResponse
    {
        $this->assertAccess($request);
        $this->assertMine($request, $inquiry);

        $data = $request->validate(
            ['label' => ['nullable', 'string', 'max:120']],
            ['label.max' => 'A design name is at most 120 characters.']
        );

        $design = $this->designOf($inquiry, $design);
        $was = $design->name();

        // Emptied on purpose falls back to "Design 3" and similar, which is
        // what an unnamed design has always been called - see name().
        $design->update([
            'label' => filled($data['label'] ?? null) ? trim($data['label']) : null,
        ]);

        $now = $design->fresh()->name();

        return back()->with('success', $was === $now
            ? 'The name is unchanged.'
            : $was.' is now called '.$now.'.');
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
                // Which round this drawing belongs to, so a redraw can be told
                // from the version it replaced without counting backwards.
                'round' => (int) $design->revision_count + 1,
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

        // An early job order already has the designs approved at the time it
        // was written. Add this newly approved drawing when the client says yes
        // later, without duplicating files that were carried across already.
        if ($inquiry->order && $inquiry->order->jobOrder) {
            foreach ($design->drawings() as $file) {
                $path = $file['path'] ?? null;
                if (! $path || $inquiry->order->jobOrder->referenceFiles()->where('path', $path)->exists()) {
                    continue;
                }

                $inquiry->order->jobOrder->referenceFiles()->create([
                    'path' => $path,
                    'original_name' => $design->name().' - '.($file['original_name'] ?? basename($path)),
                    'kind' => $file['kind'] ?? 'layout',
                    'mime' => $file['mime'] ?? null,
                    'size' => $file['size'] ?? null,
                    'uploaded_by' => $file['uploaded_by'] ?? $request->user()->id,
                ]);
            }
        }

        // A partial approval can open the job order, but floor work stays
        // locked until every design is approved.
        if ($inquiry->order && $inquiry->layoutApproved()) {
            $order = $inquiry->order;
            $order->unlockStage(\App\Models\ProductionOrder::STAGE_LAYOUT);
            $carriedLayout = $order->tasks()->where('stage', \App\Models\ProductionOrder::STAGE_LAYOUT)
                ->where('status', '!=', 'complete')->get();
            $carriedLayout
                ->each(fn ($task) => $task->forceFill([
                    'status' => 'complete',
                    'submitted_at' => $inquiry->layout_submitted_at ?? now(),
                    'approved_at' => $inquiry->layout_approved_at ?? now(),
                ])->save());
            $order->forceFill(['layout_approved_at' => $inquiry->layout_approved_at ?? now()])->save();
            $inquiry->markOrdered($order);

            // The same door as the one in ProductionOrderController: writing
            // 'complete' onto the row is not finishing the step, and it is
            // finishing it that opens the stage after. Without this, a job that
            // owes nothing waits on a payment that is never coming.
            if ($finishedLayout = $carriedLayout->last()) {
                $order->refresh()->handleTaskCompleted($finishedLayout->fresh());
            }

            // If the sample/pre-production work finished while the last
            // design was awaiting approval, retry the held batch stage now.
            $earlierWorkOpen = $order->tasks()
                ->where('stage', '<', 10)
                ->where('status', '!=', 'complete')
                ->exists();
            if (! $earlierWorkOpen) {
                $order->unlockStage(10);
            }

            return redirect()->route('orders.show', $order)
                ->with('success', 'Every remaining design is now approved. Production can continue.');
        }

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
    /**
     * Take a drawing off the design without destroying it.
     *
     * Submitting a redraw appends, so the version the client rejected stays in
     * the list and is shown beside the one that was agreed - on the brief page
     * and, worse, on the job order as a reference the floor works from.
     *
     * Which of them is superseded cannot be worked out from what is stored.
     * Two attempts at inferring it from the file count both hid real work: a
     * panel of a three-piece windbreaker set, and the hoodie from a design
     * that is a windbreaker AND a hoodie. A design of two files with one
     * revision looks the same either way. So a person says which, and this is
     * how they say it.
     *
     * Marked, not deleted. The file stays on disk and in the record, and its
     * position in the list does not move - that position is the address every
     * link to it uses, so removing the entry outright would quietly repoint
     * every other drawing on the design.
     */
    public function removeDrawing(Request $request, Inquiry $inquiry, int $design): RedirectResponse
    {
        $user = $request->user();
        $design = $this->designOf($inquiry, $design);

        // The officer whose brief it is, the artist who drew it, and leaders.
        // Not the artist leader: he moves work between artists, he does not
        // decide what the client agreed to.
        abort_unless(
            $design->artist_id === $user->id
                || $inquiry->created_by === $user->id
                || $user->isLeader()
                || ($user->leadsTeam() && $inquiry->team === $user->team),
            403
        );

        $index = (int) $request->validate(['index' => ['required', 'integer', 'min:0']])['index'];

        $files = $design->files ?? [];
        abort_unless(isset($files[$index]), 404);

        // The last one standing is not removable: a design with no drawing at
        // all cannot be approved, and the page gives no way back.
        if ($design->drawings()->count() <= 1) {
            return back()->withErrors(['drawings' =>
                'That is the only drawing on '.$design->name().'. Add the new one before taking this off.']);
        }

        $name = $files[$index]['original_name'] ?? 'the drawing';
        $files[$index]['kind'] = 'superseded';
        $design->update(['files' => $files]);

        // The job order carries these as the artist's references, and the
        // whole point is that the floor stops working from the old one.
        $order = $inquiry->order;
        if ($order?->jobOrder && ($path = $files[$index]['path'] ?? null)) {
            $order->jobOrder->referenceFiles()->where('path', $path)->delete();
        }

        return back()->with('success', $name.' is no longer shown on '.$design->name().'.');
    }

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
                || $user->isSales()
                // The artist leader decides who draws these, so he has to be
                // able to open one that is not his own.
                || $user->canMoveArtistWork(),
            403
        );

        $file = ($design->files ?? [])[$index] ?? null;
        $path = $file['path'] ?? null;

        abort_unless($path && Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response($path, $file['original_name'] ?? basename($path));
    }
}
