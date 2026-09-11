<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

/** Choosing your own password, after somebody else chose the first one. */
class ChangePasswordController extends Controller
{
    public function show(Request $request): View
    {
        return view('auth.change-password', [
            'forced' => (bool) $request->user()->must_change_password,
        ]);
    }

    public function save(Request $request): RedirectResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', Password::min(8)],
        ], [
            'current_password.current_password' => 'That is not the password you signed in with.',
            'password.confirmed' => 'The two new passwords do not match.',
        ]);

        // The point of the whole exercise: a password only they know. Keeping
        // the one they were handed would leave it known to whoever handed it over.
        if (Hash::check($data['password'], $user->password)) {
            return back()->withErrors(['password' => 'Choose a password different from the one you were given.']);
        }

        $user->forceFill([
            'password' => Hash::make($data['password']),
            'must_change_password' => false,
        ])->save();

        return redirect()->route('dashboard')
            ->with('success', 'Password changed. This is yours now — nobody else knows it.');
    }
}
