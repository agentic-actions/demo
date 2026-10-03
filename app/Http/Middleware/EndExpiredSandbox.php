<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * A hosted sandbox ends at its expires_at, not when the prune gets to it. From then on its people are signed out: a
 * page sends them to the front page with a line saying why, a JSON call answers 401, and "Try the demo" goes on to
 * start a new sandbox. An MCP client's token (a bearer request) answers 401 too, so a connector stops with the
 * sandbox even while the scheduler is behind. The prune deletes everything a few minutes later.
 */
class EndExpiredSandbox
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || $user->demo_sandbox_id === null || $user->demoSandbox?->hasExpired() === false) {
            return $next($request);
        }

        $message = __('This sandbox has expired. Start a new one.');

        if ($request->bearerToken() !== null || ! $request->hasSession()) {
            return response()->json(['message' => $message], 401);
        }

        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        if ($request->routeIs('demo.store')) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            return response()->json(['message' => $message], 401);
        }

        Inertia::flash('toast', ['type' => 'error', 'message' => $message]);

        return redirect()->route('home');
    }
}
