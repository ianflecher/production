<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Inquiry;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Storage;
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
        $this->assertAccess($request);

        return view('inquiries.index', [
            'followUps' => Inquiry::with(['client', 'officer', 'followUps.user'])
                ->visibleTo($request->user())
                ->forFollowUp()
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
            'client_office_address' => ['required_without:client_id', 'nullable', 'string', 'max:255'],
            'client_delivery_address' => ['required_without:client_id', 'nullable', 'string', 'max:255'],
            'client_tin' => ['nullable', 'string', 'max:50'],

            'what_they_want' => ['nullable', 'string', 'max:2000'],
        ], [
            'client_name.required_without' => 'Enter the first name.',
            'client_last_name.required_without' => 'Enter the last name.',
            'client_contact.required_without' => 'Enter a contact number — it is what a follow-up needs.',
            'client_office_address.required_without' => 'Enter the office address.',
            'client_delivery_address.required_without' => 'Enter the delivery address.',
        ]);

        $client = ! empty($data['client_id'])
            ? Client::findOrFail($data['client_id'])
            : Client::create([
                'name' => $data['client_name'],
                'last_name' => $data['client_last_name'],
                'contact_number' => $data['client_contact'],
                'company' => $data['client_company'] ?? null,
                'office_address' => $data['client_office_address'] ?? null,
                'delivery_address' => $data['client_delivery_address'] ?? null,
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
    public function layout(Request $request, Inquiry $inquiry): View
    {
        $this->assertAccess($request);
        $this->assertMine($request, $inquiry);

        return view('inquiries.layout', ['inquiry' => $inquiry->load('client')]);
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
            'reference_files.*' => ['file', 'mimes:jpg,jpeg,png,webp,gif,pdf,ai,psd,eps,cdr,zip', 'max:65536'],
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

        $questions = \App\Services\DesignBrief::questions();
        $answers = $inquiry->design_brief ?? [];
        $prompt = null;
        if ($answers) {
            $lines = ['You are a senior apparel graphic designer. Create a custom apparel design concept using this client brief:'];
            foreach ($answers as $key => $value) {
                if (isset($questions[$key])) {
                    $lines[] = '- '.$questions[$key]['label'].' '.(\App\Services\DesignBrief::answerLabel($key, $value) ?? $value);
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
            'clientLink' => \App\Services\PublicUrl::rewrite(route('client.inquiry-design-brief', $inquiry)),
            'clientLinkIsPrivate' => \App\Services\PublicUrl::isPrivate(\App\Services\PublicUrl::rewrite(route('client.inquiry-design-brief', $inquiry))),
            'clientLinkExpiresAt' => $inquiry->brief_expires_at,
            'clientSubmittedAt' => $inquiry->client_brief_submitted_at,
            'briefExpired' => $inquiry->briefExpired(),
        ]);
    }

    public function saveDesignBrief(Request $request, Inquiry $inquiry): RedirectResponse
    {
        $this->assertAccess($request);
        $this->assertMine($request, $inquiry);
        $questions = \App\Services\DesignBrief::questions();
        $data = $request->validate([
            'brief' => ['nullable', 'array'],
            'brief.*' => ['nullable', 'string', 'max:2000'],
            'files' => ['nullable', 'array'],
            'files.*' => ['nullable', 'array'],
            'files.*.*' => ['file', 'mimes:jpg,jpeg,png,webp,gif,pdf,ai,psd,eps,cdr,zip', 'max:65536'],
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
        if ($inquiry->layout_artist_id !== $request->user()->id) {
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

        $data = $request->validate(['reference_note' => ['nullable', 'string', 'max:2000']]);

        $hasOutput = collect($inquiry->layout_files ?? [])->contains(fn ($file) => ($file['kind'] ?? 'output') === 'output');
        if (! $hasOutput && blank($data['reference_note'] ?? null)) {
            return back()->withInput()->withErrors(['layout' => 'Upload the ChatGPT design output or add notes for the artist before continuing.']);
        }

        // Pick the artist now, not when the order is written, so the officer
        // leaves this page knowing whose desk it landed on. The same person is
        // then handed the layout task itself — a name shown here and a
        // different artist actually doing it would be worse than showing none.
        $artist = $inquiry->layoutArtist ?: \App\Services\StaffAssigner::next(User::JOB_ARTIST);

        $inquiry->update([
            'layout_reference_note' => $data['reference_note'] ?? null,
            'layout_brief_completed_at' => now(),
            'layout_artist_id' => $artist?->id,
            'layout_sent_at' => now(),
            'layout_status' => Inquiry::LAYOUT_WITH_ARTIST,
        ]);

        return redirect()->route('orders.create', ['inquiry' => $inquiry->id])
            ->with('success', $artist
                ? 'Artist layout brief saved — '.$artist->name.' has the layout. Complete the new job order.'
                : 'Artist layout brief saved. No artist is in today, so the layout will be handed out when the job order is written.');
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

        return view('inquiries.layouts', [
            'search' => $search,
            'queue' => Inquiry::with(['client', 'officer'])
                ->when(! $user->isLeader(), fn ($q) => $q->drawnBy($user))
                ->when($user->isLeader(), fn ($q) => $q->whereIn('layout_status',
                    [Inquiry::LAYOUT_WITH_ARTIST, Inquiry::LAYOUT_SUBMITTED])->open())
                ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w
                    ->where('what_they_want', 'like', "%{$search}%")
                    ->orWhereHas('client', fn ($c) => $c
                        ->where('name', 'like', "%{$search}%")
                        ->orWhere('last_name', 'like', "%{$search}%")
                        ->orWhere('company', 'like', "%{$search}%"))))
                ->orderBy('layout_sent_at')
                ->get(),
        ]);
    }

    /** The artist hands the finished layout back. */
    public function submitLayout(Request $request, Inquiry $inquiry): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->isArtist() || $user->isLeader(), 403);
        abort_unless($inquiry->layout_artist_id === $user->id || $user->isLeader(), 403);
        abort_unless($inquiry->layoutWithArtist(), 403);

        $request->validate([
            'layout_files' => ['required', 'array'],
            'layout_files.*' => ['file', 'mimes:jpg,jpeg,png,webp,gif,pdf,ai,psd,eps,cdr,zip', 'max:65536'],
        ], ['layout_files.required' => 'Attach the layout before handing it back.']);

        $files = $inquiry->layout_files ?? [];

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

        $inquiry->update([
            'layout_files' => $files,
            'layout_status' => Inquiry::LAYOUT_SUBMITTED,
            'layout_submitted_at' => now(),
            'layout_revision_note' => null,
        ]);

        \App\Models\AppNotification::toUser($inquiry->created_by,
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

        $inquiry->update([
            'layout_status' => Inquiry::LAYOUT_APPROVED,
            'layout_approved_at' => now(),
        ]);

        return redirect()->route('orders.create', ['inquiry' => $inquiry->id])
            ->with('success', 'Layout approved. Write the job order.');
    }

    /** The client wants changes. Back to the same artist with the reason. */
    public function reviseLayout(Request $request, Inquiry $inquiry): RedirectResponse
    {
        $this->assertAccess($request);
        $this->assertMine($request, $inquiry);
        abort_unless($inquiry->layoutSubmitted(), 403);

        $data = $request->validate(
            ['layout_revision_note' => ['required', 'string', 'max:2000']],
            ['layout_revision_note.required' => 'Say what the client wants changed.']
        );

        $inquiry->update([
            'layout_status' => Inquiry::LAYOUT_WITH_ARTIST,
            'layout_revision_note' => $data['layout_revision_note'],
            'layout_submitted_at' => null,
        ]);

        if ($inquiry->layout_artist_id) {
            \App\Models\AppNotification::toUser($inquiry->layout_artist_id,
                '↩ Layout needs changing',
                $inquiry->client->fullName().' — '.\Illuminate\Support\Str::limit($data['layout_revision_note'], 90),
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

        abort_unless($user->leadsTeam() && $inquiry->team === $user->team, 403);
    }
}
