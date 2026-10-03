<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * The starter kit's account features a hosted visitor must not reach, by route name, while demo.hosted is on.
 *
 * Sign-up, password reset, email verification, two-factor, passkeys and password confirmation answer 404: they mail
 * people, or ask for a password nobody knows. The security page, every part of which is off, sends the visitor to the
 * profile page. An email change, account deletion, a password change and an invitation answer 403 with a line saying
 * why. The pages show that line as a toast: an Inertia visit reads it from the response, and any other request
 * flashes it for the next page.
 *
 * Why a route guard and not Fortify's feature list: Fortify registers its routes once, at boot, from that list, and
 * Wayfinder writes the frontend's route helpers from the routes registered when `npm run build` runs. Taking routes
 * away by environment would remove helpers the auth pages import, and break the build on the host. Here the routes
 * stay registered everywhere, and the flag is read on each request, so a test switches it with config().
 */
class DisabledOnHostedDemo
{
    /**
     * Route names that do not exist on the hosted demo.
     *
     * @var list<string>
     */
    public const ABSENT = [
        'register',
        'register.store',
        'password.request',
        'password.email',
        'password.reset',
        'password.update',
        'password.confirm',
        'password.confirm.store',
        'password.confirmation',
        'verification.*',
        'two-factor.*',
        'passkey.*',
        'well-known.passkeys',
    ];

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('demo.hosted')) {
            return $next($request);
        }

        abort_if($request->routeIs(...self::ABSENT), 404);

        if ($request->routeIs('security.edit')) {
            return redirect()->route('profile.edit');
        }

        $reason = $this->refusal($request);

        if ($reason === null) {
            return $next($request);
        }

        $refusal = __($reason);

        if (! $request->header('X-Inertia')) {
            Inertia::flash('toast', ['type' => 'error', 'message' => $refusal]);
        }

        return $request->expectsJson()
            ? response()->json(['message' => $refusal], 403)
            : response($refusal, 403, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }

    /**
     * Why this request is refused on the hosted demo, as a line to translate, or null when it may go ahead.
     */
    private function refusal(Request $request): ?string
    {
        return match (true) {
            $request->routeIs('profile.update') && $this->changesEmail($request) => 'Email changes are off in this demo.',
            $request->routeIs('profile.destroy') => 'This demo account is deleted on its own when the demo ends.',
            $request->routeIs('user-password.update') => 'Passwords are off in this demo. Use your resume link to sign in again.',
            $request->routeIs('teams.invitations.store') => 'Invitations are off in this demo, because they send email.',
            default => null,
        };
    }

    /**
     * Whether a profile update asks for another email address. A name change sends the same one back. Any difference
     * counts, case included: the controller marks a changed address unverified, and verification is off here.
     */
    private function changesEmail(Request $request): bool
    {
        $email = $request->input('email');

        return $email !== null && $email !== $request->user()?->email;
    }
}
