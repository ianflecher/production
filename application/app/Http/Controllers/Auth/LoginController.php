<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class LoginController extends Controller
{
    /**
     * Lock an email + IP pair out after this many failed attempts.
     */
    private const MAX_ATTEMPTS = 5;

    private const LOCKOUT_SECONDS = 60;

    public function show(): View
    {
        return view('auth.login', [
            // Empty unless a deployment has declared its data invented.
            'demoLogins' => \App\Support\DemoLogins::all(),
            // Only where the hints are on. Otherwise this reaches the page
            // through the fill-the-form script, and the whole point was that a
            // real deployment prints nothing.
            'demoPassword' => \App\Support\DemoLogins::enabled()
                ? \App\Support\DemoLogins::password()
                : null,
        ]);
    }

    public function login(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ]);

        $throttleKey = Str::lower($credentials['email']).'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($throttleKey, self::MAX_ATTEMPTS)) {
            $seconds = RateLimiter::availableIn($throttleKey);

            throw ValidationException::withMessages([
                'email' => "Too many login attempts. Please try again in {$seconds} seconds.",
            ]);
        }

        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            RateLimiter::hit($throttleKey, self::LOCKOUT_SECONDS);

            throw ValidationException::withMessages([
                'email' => 'These credentials do not match our records.',
            ]);
        }

        if (! $request->user()->is_active) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            throw ValidationException::withMessages([
                'email' => 'This account has been deactivated. Please contact your leader.',
            ]);
        }

        RateLimiter::clear($throttleKey);

        // Stamp the login so attendance can tell who is present today. The
        // address is stamped too: staff move between PCs and each one gets its
        // address by DHCP, so this is how a file they recorded can still be
        // pointed at the machine they are actually on.
        // Always the address this sign-in came from, office or not. Keeping the
        // old one when the new sign-in was not from an office address sounded
        // careful, but it is how somebody ended up being offered a PC nobody
        // had sat at for weeks: sign in over the tunnel and the stale address
        // survived every time. An address that is not an office one is no use
        // in a file path either, and the pack knows that — it falls back to the
        // machine holding the shared drive rather than to something stale.
        $request->user()->forceFill([
            'last_login_at' => now(),
            'last_login_ip' => $request->ip(),
        ])->save();

        // When an agent logs in and is FREE (no open task), pick up ONE waiting
        // job — a released (ready) step for their team that nobody took because
        // no one was present. One project at a time stays with one person.
        $user = $request->user();
        if ($user->isAgent() && $user->job_role && \App\Services\StaffAssigner::isFree($user)) {
            $task = \App\Models\Task::where('status', 'ready')
                ->where('team', $user->job_role)
                ->whereNull('assigned_to')
                ->whereHas('order', fn ($q) => $q->where('status', 'active'))
                ->orderBy('id')
                ->first();

            if ($task) {
                $task->assignTo($user->id);
                $user->forceFill(['last_auto_assigned_at' => now()])->save();
            }
        }

        $request->session()->regenerate();

        return redirect()->intended(route('dashboard'));
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
