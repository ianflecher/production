<?php

namespace App\Http\Controllers\Hr;

use App\Http\Controllers\Controller;
use App\Models\HrApplicant;
use App\Models\HrEmployee;
use App\Models\HrJobOffer;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/** Writing the offer, and turning a yes into somebody who can log in. */
class HrJobOfferController extends Controller
{
    private function assertAccess(Request $request): void
    {
        abort_unless($request->user()->canUseHr(), 403);
    }

    /** Start one off, pre-filled with the template HR then edits. */
    public function store(Request $request, HrApplicant $applicant): RedirectResponse
    {
        $this->assertAccess($request);

        abort_unless($applicant->status === HrApplicant::STATUS_PASSED, 403);
        abort_if($applicant->offers()->whereIn('status', [HrJobOffer::STATUS_DRAFT, HrJobOffer::STATUS_SENT])->exists(), 403);

        $position = $applicant->position ?: 'Staff';

        $offer = HrJobOffer::create([
            'hr_applicant_id' => $applicant->id,
            'position' => $position,
            'scope' => HrJobOffer::defaultScope($position),
            'terms' => HrJobOffer::defaultTerms(),
            'salary_period' => 'monthly',
            'status' => HrJobOffer::STATUS_DRAFT,
            'created_by' => $request->user()->id,
        ]);

        return redirect()->route('hr.offers.edit', $offer)
            ->with('success', 'Offer started. Change the scope and the salary, then give it to them.');
    }

    public function edit(Request $request, HrJobOffer $offer)
    {
        $this->assertAccess($request);

        return view('hr.offers.edit', [
            'offer' => $offer->load('applicant'),
            'periods' => HrJobOffer::PERIODS,
        ]);
    }

    public function update(Request $request, HrJobOffer $offer): RedirectResponse
    {
        $this->assertAccess($request);
        abort_unless($offer->isOpen(), 403);

        $data = $request->validate([
            'position' => ['required', 'string', 'max:120'],
            'scope' => ['nullable', 'string', 'max:5000'],
            'salary' => ['nullable', 'numeric', 'min:0', 'max:100000000'],
            'salary_period' => ['required', 'in:'.implode(',', array_keys(HrJobOffer::PERIODS))],
            'starts_on' => ['nullable', 'date'],
            'terms' => ['nullable', 'string', 'max:5000'],
        ]);

        $offer->update($data);

        return back()->with('success', 'Offer saved.');
    }

    /** Marked as given to them — from here it is a yes or a no. */
    public function send(Request $request, HrJobOffer $offer): RedirectResponse
    {
        $this->assertAccess($request);
        abort_unless($offer->status === HrJobOffer::STATUS_DRAFT, 403);

        $offer->update(['status' => HrJobOffer::STATUS_SENT, 'sent_at' => now()]);

        return back()->with('success', 'Marked as given to them. Record their answer when you have it.');
    }

    public function decline(Request $request, HrJobOffer $offer): RedirectResponse
    {
        $this->assertAccess($request);
        abort_unless($offer->isOpen(), 403);

        $offer->update(['status' => HrJobOffer::STATUS_DECLINED, 'responded_at' => now()]);
        $offer->applicant?->update(['status' => HrApplicant::STATUS_SET_ASIDE]);

        return back()->with('success', 'Recorded as declined.');
    }

    /**
     * They said yes: make them real.
     *
     * One transaction, because three things have to be true together — the
     * login, the employee record and the offer's answer. Half of this applied
     * would leave an account nobody in HR knows about, or an employee with no
     * way to sign in.
     */
    public function accept(Request $request, HrJobOffer $offer): RedirectResponse
    {
        $this->assertAccess($request);
        abort_unless($offer->isOpen(), 403);

        $applicant = $offer->applicant;
        abort_unless($applicant, 404);

        $data = $request->validate([
            'email' => ['required', 'email', 'max:180', 'unique:users,email'],
            'job_role' => ['required', 'string', 'max:60'],
        ], [
            'email.unique' => 'Somebody already signs in with that address. Use another.',
            'email.required' => 'The account needs an address to sign in with.',
        ]);

        // Given to them in person, and good for one sign-in: the flag below
        // makes them replace it before they can use anything.
        $tempPassword = Str::upper(Str::random(4)).Str::random(4).random_int(10, 99);

        $user = DB::transaction(function () use ($offer, $applicant, $data, $tempPassword) {
            $user = User::create([
                'name' => $applicant->fullName(),
                'email' => $data['email'],
                'password' => Hash::make($tempPassword),
                'must_change_password' => true,
                'job_role' => $data['job_role'],
                'is_active' => true,
            ]);

            HrEmployee::create([
                'user_id' => $user->id,
                'hr_applicant_id' => $applicant->id,
                'hr_job_offer_id' => $offer->id,
                'position' => $offer->position,
                'salary' => $offer->salary,
                'salary_period' => $offer->salary_period,
                'started_on' => $offer->starts_on,
            ]);

            $offer->update(['status' => HrJobOffer::STATUS_ACCEPTED, 'responded_at' => now()]);
            $applicant->update(['status' => HrApplicant::STATUS_HIRED]);

            return $user;
        });

        // Shown once, on the next page. It is not stored anywhere in readable
        // form - the hash is the only copy the shop keeps.
        return redirect()->route('hr.offers.edit', $offer)
            ->with('newAccount', ['email' => $user->email, 'password' => $tempPassword])
            ->with('success', $user->name.' now has an account.');
    }
}
