<?php

namespace App\Http\Controllers;

use App\Models\AppNotification;
use App\Models\Client;
use App\Models\Inquiry;
use App\Models\InquiryDesign;
use App\Models\User;
use App\Services\DesignBrief;
use App\Services\PublicUrl;
use App\Services\StaffAssigner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * The first page of taking an order: who is asking.
 *
 * Saved on its own, before anything is known about the job, so that somebody
 * who does not order today is still a name the shop can call back. The order
 * form is the second page, and it is reached from here.
 */
class InquiryController extends Controller
{
    /** Only account officers take inquiries; leaders and admin oversee them. */
    private function assertAccess(Request $request): void
    {
        abort_unless($request->user()->isSales() || $request->user()->isLeader(), 403);
    }

    /**
     * The follow-up list: everyone who asked and has not ordered.
     *
     * Its own page rather than only a card on the dashboard, because this is
     * where the calls actually get made — the dashboard says who is waiting,
     * this is where you do something about it.
     */
    public function index(Request $request): View
    {
        // The artist leader reads this list; everybody else must be the office.
        if (! $request->user()->isArtistLead()) {
            $this->assertAccess($request);
        }

        $search = trim((string) $request->query('q', ''));

        // New drawings or ones sent back. Anything else in the box means all
        // of them, so a hand-typed or stale ?kind= shows the list rather than
        // an empty page.
        $kind = (string) $request->query('kind', '');
        $kind = in_array($kind, [Inquiry::KIND_NEW, Inquiry::KIND_REVISION], true) ? $kind : '';

        // Which book of clients. Same rule as the kind: anything unrecognised
        // means both teams rather than an empty page.
        $team = strtolower((string) $request->query('team', ''));
        $team = in_array($team, ['meta', 'vip'], true) ? $team : '';

        return view('inquiries.index', [
            'search' => $search,
            'kind' => $kind,
            'team' => $team,
            // Loaded for the badge that says which kind each row is, and for
            // isRevision() underneath it — without it that is a query a row.
            'followUps' => Inquiry::with(['client', 'officer', 'followUps.user', 'designs'])
                // Whose brief it is does not narrow what the artist leader
                // sees: any of them may be carrying a layout of his to move.
                // visibleTo() is left alone — it is asked by other pages that
                // mean it in the officer's sense.
                ->when(! $request->user()->isArtistLead(),
                    fn ($q) => $q->visibleTo($request->user()))
                ->forFollowUp()
                // Searched in the database so it reaches every name on the
                // list, not the ones that happen to be on screen. The fields
                // are the ones the row actually shows - a name, the company,
                // the number they rang from, and what they asked for.
                //
                // Kept inside its own closure on purpose: these are ORs, and
                // loose at the top level the first of them would break out of
                // visibleTo() and put other officers' clients on the page.
                ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w
                    ->where('what_they_want', 'like', "%{$search}%")
                    ->orWhereHas('client', fn ($c) => $c
                        ->where('name', 'like', "%{$search}%")
                        ->orWhere('last_name', 'like', "%{$search}%")
                        ->orWhere('company', 'like', "%{$search}%")
                        ->orWhere('contact_number', 'like', "%{$search}%"))))
                // Narrowed in the database for the same reason the search is:
                // so it means the whole list. Its ORs are closured too.
                ->when($kind !== '', fn ($q) => $q->ofDesignKind($kind))
                ->when($team !== '', fn ($q) => $q->where('team', $team))
                ->get(),
        ]);
    }

    /** Page one of a new job: the client, and what they are asking about. */
    public function create(Request $request): View
    {
        $this->assertAccess($request);

        return view('inquiries.create', [
            'clients' => Client::bySurname()->get(),
        ]);
    }

    /**
     * Save the client and the inquiry, then go on to the order form.
     *
     * The inquiry exists from this moment whether or not the order is ever
     * filled in — that is the point. Walking away from page two leaves a
     * client on the follow-up list, not nothing.
     */
    public function store(Request $request): RedirectResponse
    {
        $this->assertAccess($request);

        $data = $request->validate([
            'client_id' => ['nullable', 'integer', 'exists:clients,id'],
            'client_name' => ['required_without:client_id', 'nullable', 'string', 'max:255'],
            'client_last_name' => ['required_without:client_id', 'nullable', 'string', 'max:255'],
            'client_contact' => ['required_without:client_id', 'nullable', 'string', 'max:255'],
            'client_company' => ['nullable', 'string', 'max:255'],
            'client_address' => ['required_without:client_id', 'nullable', 'string', 'max:255'],
            'client_tin' => ['nullable', 'string', 'max:50'],

            'what_they_want' => ['nullable', 'string', 'max:2000'],
        ], [
            'client_name.required_without' => 'Enter the first name.',
            'client_last_name.required_without' => 'Enter the last name.',
            'client_contact.required_without' => 'Enter a contact number — it is what a follow-up needs.',
            'client_address.required_without' => 'Enter the address.',
        ]);

        $client = ! empty($data['client_id'])
            ? Client::findOrFail($data['client_id'])
            : Client::create([
                'name' => $data['client_name'],
                'last_name' => $data['client_last_name'],
                'contact_number' => $data['client_contact'],
                'company' => $data['client_company'] ?? null,
                // The form asks once and both columns are written, so every
                // reader — the invoice, the order sheet, the follow-up card —
                // keeps finding the address where it already looks for it.
                'office_address' => $data['client_address'] ?? null,
                'delivery_address' => $data['client_address'] ?? null,
                'tin' => $data['client_tin'] ?? null,
                'created_by' => $request->user()->id,
            ]);

        $inquiry = Inquiry::create([
            'client_id' => $client->id,
            'created_by' => $request->user()->id,
            'team' => $request->user()->team,
            'status' => Inquiry::STATUS_OPEN,
            'what_they_want' => $data['what_they_want'] ?? null,
        ]);

        return redirect()
            ->route('inquiries.layout', $inquiry)
            ->with('success', $client->fullName().' saved. They are on your follow-up list — '
                .'add the design and artist instructions next.');
    }

    /** Step two: collect exactly what the artist needs before job details. */
    /**
     * Who may open a brief's layout page.
     *
     * The artist leader is let in whoever wrote the brief: the artists drawing
     * it are his, and he cannot move work between them without seeing it. He
     * is NOT let into the rest of this controller — assertAccess still keeps
     * the client's details and the brief itself to the office.
     */
    private function assertMayReadLayout(Request $request, Inquiry $inquiry): void
    {
        if ($request->user()->isArtistLead()) {
            return;
        }

        $this->assertAccess($request);
        $this->assertMine($request, $inquiry);
    }

    public function layout(Request $request, Inquiry $inquiry): View
    {
        $this->assertMayReadLayout($request, $inquiry);

        return view('inquiries.layout', [
            'inquiry' => $inquiry->load('client'),
            // Only somebody who may MOVE a layout is asked the question, and
            // only they pay for the query.
            'artists' => $request->user()->canMoveArtistWork()
                ? User::where('is_active', true)
                    ->get()
                    ->filter(fn (User $u) => $u->isArtist())
                    ->sortBy('name')
                    ->values()
                : collect(),
        ]);
    }

    /** Correct a client's name from the inquiry they belong to. */
    public function updateClient(Request $request, Inquiry $inquiry): RedirectResponse
    {
        $this->assertAccess($request);
        $this->assertMine($request, $inquiry);

        $data = $request->validate([
            'client_name' => ['required', 'string', 'max:255'],
            'client_last_name' => ['required', 'string', 'max:255'],
        ], [
            'client_name.required' => 'Enter the first name.',
            'client_last_name.required' => 'Enter the last name.',
        ]);

        $inquiry->client()->update([
            'name' => $data['client_name'],
            'last_name' => $data['client_last_name'],
        ]);

        return back()->with('success', 'Client name updated.');
    }

    public function uploadLayout(Request $request, Inquiry $inquiry): RedirectResponse
    {
        $this->assertAccess($request);
        $this->assertMine($request, $inquiry);

        if ($inquiry->layout_sent_at) {
            return back()->withErrors(['layout' => 'Design files cannot be changed after the brief is sent to the artist.']);
        }

        $request->validate([
            'reference_files' => ['required', 'array'],
            'reference_files.*' => ['file', 'mimes:jpg,jpeg,png,webp,gif,pdf,ai,psd,eps,cdr,zip', 'max:512000'],
        ]);

        $files = $inquiry->layout_files ?? [];
        foreach ($request->file('reference_files') as $file) {
            $files[] = [
                'path' => $file->store('inquiry-layouts', 'local'),
                'original_name' => $file->getClientOriginalName(),
                'mime' => $file->getClientMimeType(),
                'size' => $file->getSize(),
                'uploaded_by' => $request->user()->id,
                'kind' => 'output',
            ];
        }
        $inquiry->update(['layout_files' => $files]);

        return back()->with('success', 'Design uploaded — it will be attached to the new job order.');
    }

    /** Remove one mistaken design upload while the brief is still a draft. */
    public function deleteLayoutFile(Request $request, Inquiry $inquiry, int $index): RedirectResponse
    {
        $this->assertAccess($request);
        $this->assertMine($request, $inquiry);

        if ($inquiry->layout_sent_at) {
            return back()->withErrors(['layout' => 'Design files cannot be changed after the brief is sent to the artist.']);
        }

        $files = array_values($inquiry->layout_files ?? []);
        $file = $files[$index] ?? null;
        abort_unless($file, 404);

        unset($files[$index]);
        $inquiry->update(['layout_files' => array_values($files) ?: null]);

        if (filled($file['path'] ?? null)) {
            Storage::disk('local')->delete($file['path']);
        }

        return back()->with('success', 'Wrong design file removed.');
    }

    public function designBrief(Request $request, Inquiry $inquiry): View
    {
        $this->assertAccess($request);
        $this->assertMine($request, $inquiry);

        if (! $inquiry->brief_token) {
            $inquiry->regenerateBriefLink();
        }

        $questions = DesignBrief::questions();
        $answers = $inquiry->design_brief ?? [];
        $prompt = null;
        if ($answers) {
            $lines = ['You are a senior apparel graphic designer. Create a custom apparel design concept using this client brief:'];
            foreach ($answers as $key => $value) {
                if (isset($questions[$key])) {
                    $lines[] = '- '.$questions[$key]['label'].' '.(DesignBrief::answerLabel($key, $value) ?? $value);
                }
            }
            $prompt = implode("\n", $lines);
        }

        return view('orders.design-brief', [
            'inquiry' => $inquiry->load('client'),
            'isInquiryBrief' => true,
            'briefRefs' => collect($inquiry->layout_files ?? [])->whereIn('kind', ['peg', 'logo']),
            'questions' => $questions,
            'answers' => $answers,
            'prompt' => $prompt,
            'clientLink' => PublicUrl::rewrite(route('client.inquiry-design-brief', $inquiry)),
            'clientLinkIsPrivate' => PublicUrl::isPrivate(PublicUrl::rewrite(route('client.inquiry-design-brief', $inquiry))),
            'clientLinkExpiresAt' => $inquiry->brief_expires_at,
            'clientSubmittedAt' => $inquiry->client_brief_submitted_at,
            'briefExpired' => $inquiry->briefExpired(),
        ]);
    }

    public function saveDesignBrief(Request $request, Inquiry $inquiry): RedirectResponse
    {
        $this->assertAccess($request);
        $this->assertMine($request, $inquiry);
        $questions = DesignBrief::questions();
        $data = $request->validate([
            'brief' => ['nullable', 'array'],
            'brief.*' => ['nullable', 'string', 'max:2000'],
            'files' => ['nullable', 'array'],
            'files.*' => ['nullable', 'array'],
            'files.*.*' => ['file', 'mimes:jpg,jpeg,png,webp,gif,pdf,ai,psd,eps,cdr,zip', 'max:512000'],
        ]);

        $answers = collect($data['brief'] ?? [])->only(array_keys($questions))
            ->filter(fn ($value) => filled($value))->map(fn ($value) => trim($value))->all();
        $files = $inquiry->layout_files ?? [];
        $allowedKinds = collect($questions)->pluck('files')->filter()->all();
        foreach ($request->file('files', []) as $kind => $uploads) {
            if (! in_array($kind, $allowedKinds, true)) {
                continue;
            }
            foreach ($uploads as $file) {
                $files[] = [
                    'path' => $file->store('inquiry-layouts', 'local'),
                    'original_name' => $file->getClientOriginalName(),
                    'mime' => $file->getClientMimeType(),
                    'size' => $file->getSize(),
                    'uploaded_by' => $request->user()->id,
                    'kind' => $kind,
                ];
            }
        }
        $inquiry->update(['design_brief' => $answers ?: null, 'layout_files' => $files ?: null]);

        return redirect()->route('inquiries.design-brief', $inquiry)
            ->with('success', 'Design questionnaire saved. Return to the artist brief when ready.');
    }

    public function reopenDesignBrief(Request $request, Inquiry $inquiry): RedirectResponse
    {
        $this->assertAccess($request);
        $this->assertMine($request, $inquiry);
        $inquiry->update(['client_brief_submitted_at' => null]);

        return redirect()->route('inquiries.design-brief', $inquiry)
            ->with('success', 'Client form reopened — the link works again for one more submission.');
    }

    public function layoutFile(Request $request, Inquiry $inquiry, int $index)
    {
        // The artist drawing it has to be able to open the reference — it is
        // the thing they are working from. assertAccess alone let only sales
        // and leaders through, so every thumbnail on the layout queue came
        // back 403 and rendered as a broken image.
        // Whoever may MOVE the layout has to be able to see what they are
        // moving — the artist leader reads these briefs to decide who should
        // draw them. Without him here every thumbnail on the brief came back
        // 403 and rendered as a broken image, which is the same fault this
        // guard was already widened once to fix.
        // Widened a third time, and this time at the right question.
        //
        // layout_artist_id is the OLD shape, from when a brief had one artist.
        // A brief now carries designs and each design carries its own artist,
        // so a brief drawn entirely through designs has that column sitting at
        // NULL - and the artist drawing it matched nothing here, was sent
        // through the officer's gates, and got 403 on every reference. The
        // references are the thing they are drawing FROM.
        $user = $request->user();

        $drawingIt = $inquiry->layout_artist_id === $user->id
            || $inquiry->designs()->where('artist_id', $user->id)->exists();

        if (! $drawingIt && ! $user->canMoveArtistWork()) {
            $this->assertAccess($request);
            $this->assertMine($request, $inquiry);
        }

        $file = ($inquiry->layout_files ?? [])[$index] ?? null;
        abort_unless($file && Storage::disk('local')->exists($file['path']), 404);

        return Storage::disk('local')->response($file['path'], $file['original_name']);
    }

    public function completeLayout(Request $request, Inquiry $inquiry): RedirectResponse
    {
        $this->assertAccess($request);
        $this->assertMine($request, $inquiry);
        // Sent once. Posting again — a stale tab, a double click, the back
        // button — would hand the same brief out a second time and re-roll the
        // artist somebody has already been told about.
        if ($inquiry->layout_sent_at) {
            return redirect()->route('orders.create', ['inquiry' => $inquiry->id]);
        }

        // reference_note is accepted only for old browser tabs and
        // integrations. New briefs put the instruction on each design, where
        // its artist can read it.
        //
        // The rest of these are the "add design" box's own fields, carried
        // here because the button belongs to that form. An officer who typed
        // the notes in and pressed Send without pressing "+ Add design" first
        // used to lose every word of it: the two were separate forms, so
        // pressing one abandoned the other, and the brief went to the artist
        // with nothing on it. Nothing said so.
        $data = $request->validate([
            'reference_note' => ['nullable', 'string', 'max:2000'],
            'label' => ['nullable', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],
            'artist_id' => ['nullable', 'integer', 'exists:users,id'],
            'how_many' => ['nullable', 'integer', 'min:1', 'max:20'],
        ]);

        // What the officer typed, wherever they typed it.
        $typed = filled($data['description'] ?? null)
            ? trim($data['description'])
            : (filled($data['reference_note'] ?? null) ? trim($data['reference_note']) : null);

        $hasOutput = collect($inquiry->layout_files ?? [])->contains(fn ($file) => ($file['kind'] ?? 'output') === 'output');
        if ($inquiry->designs()->doesntExist() && ! $hasOutput && blank($typed)) {
            return back()->withInput()->withErrors(['layout' => 'Add a design description or upload the ChatGPT design output before continuing.']);
        }

        // Pick the artist now, not when the order is written, so the officer
        // leaves this page knowing whose desk it landed on. The same person is
        // then handed the layout task itself — a name shown here and a
        // different artist actually doing it would be worse than showing none.
        $artist = $inquiry->layoutArtist ?: StaffAssigner::next(User::JOB_ARTIST);

        // A brief with no designs listed is one design: the officer who did
        // not need the list should not have to use it. It is listed with
        // whatever they typed into the add box - the name, the count, the
        // artist and the notes - through the same rule "+ Add design" uses,
        // so the two buttons cannot drift apart again.
        if ($inquiry->designs()->doesntExist()) {
            $inquiry->addDesigns(
                $data['label'] ?? null,
                $typed,
                isset($data['artist_id']) ? User::find($data['artist_id']) : $artist,
                (int) ($data['how_many'] ?? 1)
            );
        }

        // Preserve any old one-note submission by copying it only onto designs
        // that have not yet been given their own instructions.
        if (filled($typed)) {
            $inquiry->designs()->whereNull('description')->update([
                'description' => $typed,
            ]);
        }

        $hasDesignDescription = $inquiry->designs()
            ->whereNotNull('description')
            ->where('description', '!=', '')
            ->exists();

        if (! $hasOutput && ! $hasDesignDescription) {
            return back()->withInput()->withErrors(['layout' => 'Upload the ChatGPT design output or add a description to at least one design before continuing.']);
        }

        // Every design goes out at this moment, whoever is drawing it. A design
        // added later is sent as it is added - see InquiryDesignController.
        $inquiry->designs()->whereNull('sent_at')->update(['sent_at' => now()]);

        // Briefs created before designs were split into their own rows could
        // carry the old enquiry-only state, "brief". Once one is sent, that
        // state means exactly "with artist"; leaving it unchanged makes the
        // artist queue (correctly) filter the row out. Normalize it here too,
        // so an old draft that is sent today cannot disappear.
        $inquiry->designs()
            ->where('status', Inquiry::LAYOUT_BRIEF)
            ->whereNotNull('sent_at')
            ->update(['status' => InquiryDesign::STATUS_WITH_ARTIST]);

        // Anyone left without a name takes whoever is in today, so a brief sent
        // on a quiet morning does not sit on nobody's desk.
        $inquiry->designs()->whereNull('artist_id')->get()
            ->each(fn ($design) => $design->update(['artist_id' => $artist?->id]));

        $inquiry->update([
            'layout_reference_note' => $data['reference_note'] ?? null,
            'layout_brief_completed_at' => now(),
            // Kept in step with the designs: the officer's lists still read
            // this column, and the artist named here is the one the stage-1
            // Layout task is handed to when the order is written.
            'layout_artist_id' => $inquiry->designs()->value('artist_id') ?: $artist?->id,
            'layout_sent_at' => now(),
            'layout_status' => Inquiry::LAYOUT_WITH_ARTIST,
        ]);

        // Told once each, however many designs they were given.
        $inquiry->load('designs.artist');

        foreach ($inquiry->designs->groupBy('artist_id') as $artistId => $theirs) {
            AppNotification::toUser((int) $artistId,
                '🎨 A layout brief for you',
                $inquiry->client->fullName().' — '.$theirs->count().' '
                    .Str::plural('design', $theirs->count()).' to draw.',
                route('inquiries.layouts'));
        }

        $count = $inquiry->designs->count();
        $names = $inquiry->designs->pluck('artist.name')->filter()->unique()->implode(' and ');

        return redirect()->route('orders.create', ['inquiry' => $inquiry->id])
            ->with('success', $names
                ? 'Brief sent — '.$count.' '.Str::plural('design', $count)
                    .' with '.$names.'. Complete the new job order.'
                : 'Brief saved. No artist is in today, so the designs will be handed out when somebody is.');
    }

    /**
     * Hand a layout to a different artist, before there is a job order.
     *
     * The artist is picked automatically when the brief is sent, and until now
     * nothing could change it until the job order existed — so an artist who
     * went home sick took the layout with them and the officer could only wait.
     * A leader can move it; the layout, its references and anything already
     * said about it stay with the inquiry and follow the new artist.
     */
    public function reassignLayoutArtist(Request $request, Inquiry $inquiry): RedirectResponse
    {
        abort_unless($request->user()->canMoveArtistWork(), 403);

        $data = $request->validate(
            ['layout_artist_id' => ['required', 'integer', 'exists:users,id']],
            ['layout_artist_id.required' => 'Choose who is drawing it.']
        );

        $artist = User::findOrFail($data['layout_artist_id']);

        if (! $artist->isArtist() || ! $artist->is_active) {
            return back()->withErrors(['layout_artist_id' => 'That person is not an artist who can take it.']);
        }

        $previous = $inquiry->layoutArtist;

        if ($previous?->id === $artist->id) {
            return back()->with('success', $artist->name.' already has it.');
        }

        $inquiry->update(['layout_artist_id' => $artist->id]);

        // The designs move with it. This is the whole-brief handover - moving
        // ONE design of six is the per-design endpoint - so everything not yet
        // approved goes across, and what the client has already said yes to
        // stays with whoever drew it.
        $inquiry->designs()
            ->where('status', '!=', InquiryDesign::STATUS_APPROVED)
            ->update(['artist_id' => $artist->id]);

        // Both of them need to know: one has work that is no longer theirs, the
        // other has work they have not been told about.
        AppNotification::toUser($artist->id,
            '🎨 A layout was handed to you',
            $inquiry->client?->fullName().' — '.($inquiry->what_they_want ?: 'layout'),
            route('inquiries.layouts'));

        if ($previous) {
            AppNotification::toUser($previous->id,
                '↪ A layout moved off your queue',
                $inquiry->client?->fullName().' is with '.$artist->name.' now.',
                route('inquiries.layouts'));
        }

        return back()->with('success', $previous
            ? 'Moved from '.$previous->name.' to '.$artist->name.'.'
            : $artist->name.' has the layout.');
    }

    /** Log a chase, and say when to chase again. */
    public function followUp(Request $request, Inquiry $inquiry): RedirectResponse
    {
        $this->assertAccess($request);
        $this->assertMine($request, $inquiry);

        $data = $request->validate([
            'note' => ['required', 'string', 'max:2000'],
        ], [
            'note.required' => 'Say what they said — that is the point of logging it.',
        ]);

        $inquiry->followUps()->create([
            'user_id' => $request->user()->id,
            'note' => $data['note'],
        ]);

        // Back to whichever list the call was logged from — the Follow-ups
        // tab, usually. Sending them to the dashboard threw away their place.
        return back()->with('success', 'Follow-up logged for '.$inquiry->client->fullName().'.');
    }

    /**
     * An officer touches their own inquiries; a team leader touches their
     * team's. This is the whole of what leading a team allows.
     */
    /* ==================== The artist's side ==================== */

    /**
     * The layouts waiting on this artist.
     *
     * Their own list, not their task list: there is no job order yet, so there
     * is no task to put on it. That is the point of drawing the layout first —
     * nothing is committed to the books until the client likes the design.
     */
    public function layoutQueue(Request $request): View
    {
        $user = $request->user();
        abort_unless($user->isArtist() || $user->isLeader(), 403);

        // Searched in the DATABASE, so it reaches every layout on the queue and
        // not just the ones that happen to have been drawn on screen. The
        // things somebody is told over the phone: the client, their company,
        // and what they asked for.
        $search = trim((string) $request->query('q', ''));

        // DESIGNS, not enquiries. One brief can carry six of them split
        // between two artists, and each person's queue is the designs on their
        // own desk - not every brief they appear somewhere inside.
        $designs = InquiryDesign::query()
            ->with(['inquiry.client', 'inquiry.officer', 'artist'])
            // Only briefs that have actually been sent. A design is created
            // as with_artist the moment it is added, so without this an
            // artist saw work the officer was still writing up - and could
            // start drawing from instructions that were about to change.
            ->when(! $user->isLeader(), fn ($q) => $q->drawnBy($user)
                ->whereHas('inquiry', fn ($i) => $i->whereNotNull('layout_sent_at')))
            ->when($user->isLeader(), fn ($q) => $q
                ->whereIn('status', [
                    InquiryDesign::STATUS_WITH_ARTIST,
                    InquiryDesign::STATUS_SUBMITTED,
                ])
                ->whereHas('inquiry', fn ($i) => $i->open()->whereNotNull('layout_sent_at')))
            ->when($search !== '', fn ($q) => $q->whereHas('inquiry', fn ($i) => $i
                ->where('what_they_want', 'like', "%{$search}%")
                ->orWhereHas('client', fn ($c) => $c
                    ->where('name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%")
                    ->orWhere('company', 'like', "%{$search}%"))))
            ->get()
            ->sortBy([
                fn ($a, $b) => optional($a->inquiry->layout_sent_at) <=> optional($b->inquiry->layout_sent_at),
                fn ($a, $b) => $a->position <=> $b->position,
            ]);

        return view('inquiries.layouts', [
            'search' => $search,
            // Grouped under the brief they belong to, so a kit reads as one
            // client's job rather than six unrelated cards.
            'queue' => $designs->groupBy('inquiry_id'),
        ]);
    }

    /** The artist hands the finished layout back. */
    public function submitLayout(Request $request, Inquiry $inquiry): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->isArtist() || $user->isLeader(), 403);
        abort_unless($inquiry->layout_artist_id === $user->id || $user->isLeader(), 403);
        // A layout already handed back can be uploaded over. The client asks
        // for changes through the officer, but they also ask the artist
        // directly, and a revised drawing had nowhere to go until the officer
        // happened to send it back — so the artist sat on it.
        abort_unless($inquiry->layoutWithArtist() || $inquiry->layoutSubmitted(), 403);

        $request->validate([
            'layout_files' => ['required', 'array'],
            'layout_files.*' => ['file', 'mimes:jpg,jpeg,png,webp,gif,pdf,ai,psd,eps,cdr,zip', 'max:512000'],
        ], ['layout_files.required' => 'Attach the layout before handing it back.']);

        // The drawing belongs to a DESIGN. This endpoint is the whole-brief
        // one - the artist hands back everything of theirs on it at once -
        // and it is what a one-design brief has always used.
        $mine = $inquiry->designs()
            ->when(! $user->isLeader(), fn ($q) => $q->where('artist_id', $user->id))
            ->whereIn('status', [
                InquiryDesign::STATUS_WITH_ARTIST,
                InquiryDesign::STATUS_SUBMITTED,
            ])
            ->get();

        if ($mine->isEmpty()) {
            return back()->withErrors(['layout_files' => 'There is nothing on this brief waiting for you to draw.']);
        }

        $files = [];

        foreach ($request->file('layout_files') as $file) {
            $files[] = [
                'path' => $file->store('inquiry-layouts', 'local'),
                'original_name' => $file->getClientOriginalName(),
                'mime' => $file->getClientMimeType(),
                'size' => $file->getSize(),
                'uploaded_by' => $user->id,
                // Kept apart from the officer's brief material: this is the
                // drawing, that was the reference.
                'kind' => 'layout',
            ];
        }

        // Several designs handed back together get the same batch of files:
        // the artist chose to answer the brief as a whole. Handing them back
        // one at a time is the per-design endpoint.
        foreach ($mine as $design) {
            $design->update([
                'files' => array_merge($design->files ?? [], $files),
                'status' => InquiryDesign::STATUS_SUBMITTED,
                'submitted_at' => now(),
                'revision_note' => null,
            ]);
        }

        $inquiry->update([
            'layout_files' => array_merge($inquiry->layout_files ?? [], $files),
            'layout_revision_note' => null,
        ]);

        $inquiry->syncLayoutStatus();

        AppNotification::toUser($inquiry->created_by,
            '🎨 Layout ready for the client',
            $inquiry->client->fullName().' — '.$user->name.' has finished the layout.',
            route('inquiries.layout', $inquiry));

        return back()->with('success', 'Layout handed back to '.($inquiry->officer?->name ?? 'the account officer').'.');
    }

    /* ==================== The officer's decision ==================== */

    /** The client said yes. This is the only thing that opens the job order. */
    public function approveLayout(Request $request, Inquiry $inquiry): RedirectResponse
    {
        $this->assertAccess($request);
        $this->assertMine($request, $inquiry);
        abort_unless($inquiry->layoutSubmitted(), 403);

        // Says yes to everything still waiting on the client. Answering them
        // one at a time is the per-design endpoint; this is the officer who
        // has the client on the phone about the lot.
        $inquiry->designs()
            ->where('status', InquiryDesign::STATUS_SUBMITTED)
            ->update([
                'status' => InquiryDesign::STATUS_APPROVED,
                'approved_at' => now(),
            ]);

        $inquiry->syncLayoutStatus();

        return redirect()->route('orders.create', ['inquiry' => $inquiry->id])
            ->with('success', 'Layout approved. Write the job order.');
    }

    /** The client wants changes. Back to the same artist with the reason. */
    public function reviseLayout(Request $request, Inquiry $inquiry): RedirectResponse
    {
        $this->assertAccess($request);
        $this->assertMine($request, $inquiry);
        abort_unless($inquiry->layoutSubmitted(), 403);

        // Three rounds is what the client is promised. A leader can still send
        // it back a fourth time — giving a round away is a decision somebody
        // makes, not something the form should quietly allow everybody.
        if ($inquiry->revisionsUsedUp() && ! $request->user()->isLeader()) {
            return back()->withErrors(['layout_revision_note' => 'This layout has already had its '.Inquiry::LAYOUT_REVISION_LIMIT
                .' revisions. A leader can send it back again.']);
        }

        $data = $request->validate([
            'layout_revision_note' => ['required', 'string', 'max:2000'],
            // What the client wants changed is often easier shown than said —
            // a marked-up screenshot, a photo, the reference they meant. The
            // note stays required; the files are the optional half.
            'revision_files' => ['nullable', 'array'],
            'revision_files.*' => ['file', 'mimes:jpg,jpeg,png,webp,gif,pdf,ai,psd,eps,cdr,zip', 'max:512000'],
        ], [
            'layout_revision_note.required' => 'Say what the client wants changed.',
        ]);

        $files = $inquiry->layout_files ?? [];
        foreach ($request->file('revision_files', []) as $file) {
            $files[] = [
                'path' => $file->store('inquiry-layouts', 'local'),
                'original_name' => $file->getClientOriginalName(),
                'mime' => $file->getClientMimeType(),
                'size' => $file->getSize(),
                'uploaded_by' => $request->user()->id,
                'kind' => 'revision',
            ];
        }

        // Everything waiting on the client goes back. Sending ONE design back
        // while the others stand is the per-design endpoint - this is the
        // officer whose client rejected the lot.
        foreach ($inquiry->designs()->where('status', InquiryDesign::STATUS_SUBMITTED)->get() as $design) {
            $design->update([
                'status' => InquiryDesign::STATUS_WITH_ARTIST,
                'revision_note' => $data['layout_revision_note'],
                // Counted even when a leader goes past the limit: the number is
                // a record of what the job actually cost, not just a gate.
                'revision_count' => (int) $design->revision_count + 1,
                'submitted_at' => null,
            ]);
        }

        $inquiry->update([
            'layout_revision_note' => $data['layout_revision_note'],
            'layout_submitted_at' => null,
            'layout_files' => $files ?: null,
            'layout_revision_count' => (int) $inquiry->layout_revision_count + 1,
        ]);

        $inquiry->syncLayoutStatus();

        if ($inquiry->layout_artist_id) {
            AppNotification::toUser($inquiry->layout_artist_id,
                '↩ Layout needs changing',
                $inquiry->client->fullName().' — '.Str::limit($data['layout_revision_note'], 90),
                route('inquiries.layouts'));
        }

        return back()->with('success', 'Sent back to '.($inquiry->layoutArtist?->name ?? 'the artist').'.');
    }

    private function assertMine(Request $request, Inquiry $inquiry): void
    {
        $user = $request->user();

        if ($user->isLeader() || $inquiry->created_by === $user->id) {
            return;
        }

        // Named, not just refused. The designing board shows the whole shop's
        // work on purpose, so people click through to briefs that are not
        // theirs as a matter of course - and a bare 403 reads as a broken
        // system rather than as somebody else's client. See errors/403.
        abort_unless($user->leadsTeam() && $inquiry->team === $user->team,
            403, self::notYoursMessage($inquiry));
    }

    /** Whose brief this is, for a refusal somebody can act on. */
    private static function notYoursMessage(Inquiry $inquiry): string
    {
        $officer = $inquiry->officer?->name;
        $team = strtoupper(trim((string) $inquiry->team));
        $client = $inquiry->client?->fullName();

        $whose = $officer
            ? $officer.($team ? ' on '.$team : '')
            : 'another account officer';

        return $client
            ? $client.'\'s brief belongs to '.$whose.'.'
            : 'This brief belongs to '.$whose.'.';
    }
}
