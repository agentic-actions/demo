<?php

namespace App\Http\Controllers;

use App\Actions\Demo\CreateSandbox;
use App\Models\DemoSandbox;
use App\Models\Team;
use App\Models\User;
use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * The hosted demo's way in: "Try the demo" makes a sandbox and signs the visitor in as its Acme owner, the signed
 * resume link signs them back in from another browser, and "View as" signs in as one of the sandbox's other people.
 * Every sign-in starts a new session id. None of this exists unless demo.hosted is on.
 */
class DemoController extends Controller implements HasMiddleware
{
    /**
     * Answer 404 everywhere but the hosted demo.
     *
     * @return list<Middleware>
     */
    public static function middleware(): array
    {
        return [
            new Middleware(fn (Request $request, Closure $next): Response => config('demo.hosted') ? $next($request) : abort(404)),
        ];
    }

    /**
     * Start a sandbox for the visitor and open its Acme board, unless the demo already holds as many as it can, or
     * this address, or everyone together, started as many as an hour allows.
     *
     * The demo-start limiter turns most extra requests away first, but it checks every request's count before any of
     * them adds to it, so a burst sent at once gets through together. Here the counts come from the sandboxes
     * themselves, read and added to under one lock, so a burst cannot pass them.
     */
    public function store(Request $request, CreateSandbox $createSandbox): Response
    {
        try {
            $sandbox = Cache::lock('demo-start', 10)->block(5, function () use ($request, $createSandbox): DemoSandbox|Response {
                if (DemoSandbox::query()->live()->count() >= (int) config('demo.max_live')) {
                    return $this->unavailable($request, __('The demo is full right now. Try again in a few minutes.'));
                }

                $this->ensureStartsLeft((string) $request->ip());

                return $createSandbox->handle($request->ip());
            });
        } catch (LockTimeoutException) {
            return $this->unavailable($request, __('The demo is busy right now. Try again in a moment.'));
        }

        if ($sandbox instanceof Response) {
            return $sandbox;
        }

        /** @var User $visitor */
        $visitor = $sandbox->visitor;

        $this->signIn($request, $visitor);

        return redirect()->route('board', ['current_team' => $this->homeTeam($visitor)->slug]);
    }

    /**
     * Refuse with 429, as the demo-start limiter does, once this address or everyone together started as many
     * sandboxes in the last hour as config('demo.starts_per_hour') allows. Retry-After is when the oldest of them
     * leaves the hour.
     *
     * @throws ThrottleRequestsException
     */
    private function ensureStartsLeft(string $ip): void
    {
        $since = now()->subHour();

        $checks = [
            [DemoSandbox::query()->where('ip_hash', DemoSandbox::hashIp($ip)), (int) config('demo.starts_per_hour.address')],
            [DemoSandbox::query(), (int) config('demo.starts_per_hour.all')],
        ];

        foreach ($checks as [$query, $allowed]) {
            $started = $query->where('created_at', '>', $since)->orderByDesc('created_at')->limit($allowed)->pluck('created_at');

            if ($started->count() >= $allowed) {
                $retryAfter = max(1, (int) ceil(now()->diffInSeconds($started->last()->addHour())));

                throw new ThrottleRequestsException(headers: ['Retry-After' => $retryAfter]);
            }
        }
    }

    /**
     * The demo cannot take a new sandbox for now: 503, with the line as JSON or as text.
     */
    private function unavailable(Request $request, string $message): Response
    {
        return $request->expectsJson()
            ? response()->json(['message' => $message], 503, ['Retry-After' => '300'])
            : response($message, 503, ['Content-Type' => 'text/plain; charset=UTF-8', 'Retry-After' => '300']);
    }

    /**
     * Sign back in as the sandbox's visitor from a signed link that expires with the sandbox. A sign-in page the
     * visitor was sent to, such as an MCP client's OAuth consent, comes back first.
     */
    public function resume(Request $request, DemoSandbox $sandbox): RedirectResponse
    {
        $visitor = $sandbox->visitor;

        abort_if($visitor === null || $sandbox->expires_at->isPast(), 404);

        $this->signIn($request, $visitor);

        return redirect()->intended(route('board', ['current_team' => $this->homeTeam($visitor)->slug]));
    }

    /**
     * Sign in as another person of the same sandbox, or back as the visitor, and open their board.
     */
    public function viewAs(Request $request, User $persona): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        abort_if(
            $user->demo_sandbox_id === null
                || $persona->demo_sandbox_id !== $user->demo_sandbox_id
                || $user->demoSandbox === null
                || $user->demoSandbox->expires_at->isPast(),
            403,
        );

        $this->signIn($request, $persona);

        return redirect()->route('board', ['current_team' => $this->homeTeam($persona)->slug]);
    }

    /**
     * Sign the person in on a new session id, so nothing from the previous session's id carries over, and clear the
     * browser's page history: the hosted demo encrypts it (HandleInertiaRequests), so Back after "View as" fetches
     * the page again as the person signed in now instead of showing the previous person's copy.
     */
    private function signIn(Request $request, User $user): void
    {
        Auth::guard('web')->login($user);

        $request->session()->regenerate();

        Inertia::clearHistory();
    }

    /**
     * The team a person lands on: their current one, or else their personal team.
     */
    private function homeTeam(User $user): Team
    {
        $team = $user->currentTeam ?? $user->personalTeam();

        abort_if($team === null, 403);

        return $team;
    }
}
