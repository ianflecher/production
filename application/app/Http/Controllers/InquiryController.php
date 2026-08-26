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
        $this->assertAccess($request);
        $this->assertMine($request, $inquiry);
        $file = ($inquiry->layout_files ?? [])[$index] ?? null;
        abort_unless($file && Storage::disk('local')->exists($file['path']), 404);

        return Storage::disk('local')->response($file['path'], $file['original_name']);
    }

    public function completeLayout(Request $request, Inquiry $inquiry): RedirectResponse
    {
        $this->assertAccess($request);
        $this->assertMine($request, $inquiry);
        $data = $request->validate(['reference_note' => ['nullable', 'string', 'max:2000']]);

        $hasOutput = collect($inquiry->layout_files ?? [])->contains(fn ($file) => ($file['kind'] ?? 'output') === 'output');
        if (! $hasOutput && blank($data['reference_note'] ?? null)) {
            return back()->withInput()->withErrors(['layout' => 'Upload the ChatGPT design output or add notes for the artist before continuing.']);
        }

        $inquiry->update([
            'layout_reference_note' => $data['reference_note'] ?? null,
            'layout_brief_completed_at' => now(),
        ]);

        return redirect()->route('orders.create', ['inquiry' => $inquiry->id])
            ->with('success', 'Artist layout brief saved. Complete the new job order to send it to the artist.');
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

        return redirect()->route('dashboard')->with('success', 'Follow-up logged for '.$inquiry->client->fullName().'.');
    }

    /**
     * An officer touches their own inquiries; a team leader touches their
     * team's. This is the whole of what leading a team allows.
     */
    private function assertMine(Request $request, Inquiry $inquiry): void
    {
        $user = $request->user();

        if ($user->isLeader() || $inquiry->created_by === $user->id) {
            return;
        }

        abort_unless($user->leadsTeam() && $inquiry->team === $user->team, 403);
    }
}
