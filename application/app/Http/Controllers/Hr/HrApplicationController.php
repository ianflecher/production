<?php

namespace App\Http\Controllers\Hr;

use App\Http\Controllers\Controller;
use App\Models\AppNotification;
use App\Models\HrApplicant;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

/**
 * The public application form — the one page of the HR module a stranger sees.
 *
 * Unauthenticated on purpose: somebody who does not work here yet cannot log
 * in to apply. It follows the same shape as the client design questionnaire,
 * which is already a public page on this system.
 *
 * Because anyone on the internet can post to it, it is rate limited at the
 * route and everything it accepts is validated and stored privately. It reads
 * nothing and changes nothing outside hr_applicants.
 */
class HrApplicationController extends Controller
{
    public function show(): View
    {
        return view('hr.apply', [
            'positions' => User::positionGroups(),
        ]);
    }

    public function submit(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'contact_number' => ['required', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:180'],
            'address' => ['nullable', 'string', 'max:255'],
            'birthdate' => ['nullable', 'date', 'before:today'],
            'position' => ['nullable', 'string', 'max:100'],
            'about' => ['nullable', 'string', 'max:2000'],
            // The camera hands back a data URL; the file input is the fallback
            // for a phone that will not give the page its camera.
            'photo_data' => ['nullable', 'string'],
            'photo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:20480'],
        ], [
            'first_name.required' => 'Enter your first name.',
            'last_name.required' => 'Enter your last name.',
            'contact_number.required' => 'Enter a number we can reach you on.',
        ]);

        $applicant = HrApplicant::create([
            'first_name' => $data['first_name'],
            'last_name' => $data['last_name'],
            'contact_number' => $data['contact_number'],
            'email' => $data['email'] ?? null,
            'address' => $data['address'] ?? null,
            'birthdate' => $data['birthdate'] ?? null,
            'position' => $data['position'] ?? null,
            'about' => $data['about'] ?? null,
            'photo_path' => $this->storePhoto($request),
            'status' => HrApplicant::STATUS_NEW,
            'applied_at' => now(),
        ]);

        $this->tellTheOffice($applicant);

        return redirect()->route('hr.apply.thanks');
    }

    public function thanks(): View
    {
        return view('hr.apply-thanks');
    }

    /**
     * The photo, from the camera or the file box.
     *
     * Kept on the private disk. A photograph of a person who does not work
     * here is not something to leave in /public where the URL can be guessed.
     */
    private function storePhoto(Request $request): ?string
    {
        if ($request->hasFile('photo')) {
            return $request->file('photo')->store('hr-applicant-photos', 'local');
        }

        $raw = (string) $request->input('photo_data');

        if ($raw === '') {
            return null;
        }

        // Only the shapes our own canvas produces. Anything else is somebody
        // posting by hand, and it is not written to disk.
        if (! preg_match('#^data:image/(jpeg|png|webp);base64,#', $raw, $m)) {
            return null;
        }

        $binary = base64_decode(substr($raw, strpos($raw, ',') + 1), true);

        // A camera frame is tens of kilobytes. The ceiling is for whoever
        // posts the form directly rather than for the page.
        if ($binary === false || strlen($binary) > 20 * 1024 * 1024) {
            return null;
        }

        // Believe the bytes, not the label on them.
        if (! @getimagesizefromstring($binary)) {
            return null;
        }

        $ext = $m[1] === 'jpeg' ? 'jpg' : $m[1];
        $path = 'hr-applicant-photos/'.\Illuminate\Support\Str::random(40).'.'.$ext;
        Storage::disk('local')->put($path, $binary);

        return $path;
    }

    /** Somebody applied — tell the desks that do the hiring. */
    private function tellTheOffice(HrApplicant $applicant): void
    {
        $title = '🧑‍💼 New job application';
        $body = $applicant->fullName().($applicant->position ? ' — '.$applicant->position : '');
        $url = route('hr.applicants.show', $applicant);

        // HR first, then the people who sit in on the interviews.
        foreach ([User::JOB_HR, User::ROLE_SUPER_ADMIN, User::ROLE_LEADER, User::JOB_SUPERVISOR] as $role) {
            AppNotification::toRole($role, $title, $body, $url);
        }
    }
}
