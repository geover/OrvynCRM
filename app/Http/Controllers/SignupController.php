<?php

namespace App\Http\Controllers;

use App\Models\AccountUser;
use App\Models\Signup;
use App\Services\Tenancy\CompanySession;
use App\Services\Tenancy\Recaptcha;
use App\Services\Tenancy\Signups;
use App\Services\Tenancy\SignupSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * Create your own workspace: ask (company, name, e-mail), confirm the e-mail, choose a password. Open to visitors, so every step is
 * throttled, never says whether an address already has an account, and creates nothing before the e-mail is confirmed.
 */
class SignupController extends Controller
{
    public function create(SignupSettings $settings): Response
    {
        return Inertia::render('Auth/Signup', ['mode' => $settings->mode()]);
    }

    public function store(Request $request, SignupSettings $settings, Signups $signups, Recaptcha $recaptcha): RedirectResponse
    {
        abort_unless($settings->accepting(), 403, 'Sign-ups are closed.');
        if (! $recaptcha->passes($request, 'signup')) {
            throw ValidationException::withMessages(['recaptcha' => 'We could not confirm that you are a person. Please try again.']);
        }

        $d = $request->validate([
            'company' => ['required', 'string', 'min:2', 'max:120'],
            'first_name' => ['required', 'string', 'max:80'],
            'last_name' => ['required', 'string', 'max:80'],
            'email' => ['required', 'email:rfc', 'max:190'],
            'phone' => ['nullable', 'string', 'max:40', 'regex:/^\+?[0-9 ()\-.]{6,25}$/'],
            'timezone' => ['nullable', 'string', 'max:64'],
            'marketing' => ['sometimes', 'boolean'],
            'accept_terms' => ['accepted'],
        ], ['accept_terms.accepted' => 'Please accept the Terms of Use and the Privacy Policy to continue.', 'phone.regex' => 'Enter a phone number such as +15551234567.']);

        if ($signups->blockedAddress($d['email'])) {
            throw ValidationException::withMessages(['email' => 'Please use your work e-mail address: addresses from throw-away inbox services cannot be used.']);
        }

        // a bot fills the hidden field; it is thanked like everybody else and nothing is stored
        $quiet = $request->filled('website') || RateLimiter::tooManyAttempts($key = 'signup-email:'.sha1(mb_strtolower($d['email'])), 3);
        if (! $quiet) {
            RateLimiter::hit($key, 3600);
            $signups->start($d + ['marketing' => $request->boolean('marketing')], $request->ip(), $request->userAgent());
        }

        return redirect('/signup/sent')->with('signup_email', mb_strtolower($d['email']));
    }

    public function sent(Request $request, SignupSettings $settings): Response|RedirectResponse
    {
        $email = $request->session()->get('signup_email');
        if (! $email) {
            return redirect('/signup');
        }
        $request->session()->keep('signup_email');             // a reload keeps the page

        return Inertia::render('Auth/Signup', ['mode' => 'sent', 'email' => $email, 'approval' => $settings->mode() === SignupSettings::APPROVAL]);
    }

    public function resend(Request $request, Signups $signups): RedirectResponse
    {
        $email = (string) $request->session()->get('signup_email', '');
        $email !== '' && $signups->resend($email);
        $request->session()->keep('signup_email');

        return back()->with('success', 'If that address is waiting for confirmation, a new e-mail is on its way.');
    }

    /** The link in the e-mail. */
    public function show(string $token, Signups $signups, SignupSettings $settings): Response|RedirectResponse
    {
        $signup = $signups->find($token);
        if (! $signup || ($signup->isExpired() && $signup->status !== Signup::COMPLETED)) {
            return $this->status('invalid');
        }
        if ($signup->status === Signup::COMPLETED) {
            return redirect('/login')->with('success', 'Your workspace is already set up. Sign in to open it.');
        }
        if ($signup->status === Signup::REJECTED) {
            return $this->status('rejected', $signup);
        }
        if (! $settings->accepting() && $signup->status !== Signup::APPROVED) {
            return $this->status('closed', $signup);
        }

        $signup = $signups->verify($signup);

        return match (true) {
            $signup->status === Signup::PENDING_APPROVAL => $this->status('pending_approval', $signup),
            $signup->status === Signup::COMPLETING => $this->status('busy', $signup),
            $signup->isReady() => Inertia::render('Auth/SignupComplete', ['token' => $token, 'company' => $signup->company_name, 'name' => $signup->fullName(), 'email' => $signup->email]),
            default => $this->status('invalid'),
        };
    }

    /** The password is chosen: the company is created and the person is signed straight in. */
    public function complete(Request $request, string $token, Signups $signups, CompanySession $companies): RedirectResponse
    {
        $signup = $signups->find($token);
        if (! $signup?->isReady()) {
            return redirect("/signup/{$token}");
        }
        $request->validate(['password' => ['required', 'confirmed', Password::min(10)->mixedCase()->numbers()]]);

        try {
            $tenant = $signups->complete($signup, $request->input('password'));
        } catch (Throwable $e) {
            if ($e instanceof ValidationException) {
                throw $e;
            }
            report($e);

            return back()->withErrors(['password' => 'We could not create your workspace just now. Please try again in a moment.']);
        }

        $identity = AccountUser::where('email', $signup->email)->firstOrFail();
        $request->session()->regenerate();
        $request->session()->put('identity_id', $identity->id);

        return $companies->enter($request, $identity, $tenant)->with('success', 'Welcome! Your workspace is ready.');
    }

    private function status(string $state, ?Signup $signup = null): Response
    {
        return Inertia::render('Auth/SignupStatus', ['state' => $state, 'company' => $signup?->company_name, 'email' => $signup?->email]);
    }
}
