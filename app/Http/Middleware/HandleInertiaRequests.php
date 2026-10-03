<?php

namespace App\Http\Middleware;

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Middleware;
use Symfony\Component\HttpFoundation\Response;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Handle an incoming request. The hosted demo encrypts the browser's page history, so the sign-ins that clear it
     * (DemoController) leave no earlier person's page for Back to show: Inertia fetches it again instead. Only over
     * HTTPS, which the host has: the browser's crypto API exists only on a secure page, and without it Inertia stops
     * every visit with "Unable to encrypt history".
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        Inertia::encryptHistory(config('demo.hosted') && $request->isSecure());

        return parent::handle($request, $next);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $user,
            ],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
            'currentTeam' => fn () => $user?->currentTeam ? $user->toUserTeam($user->currentTeam) : null,
            'teams' => fn () => $user?->toUserTeams(includeCurrent: true) ?? [],
            'demo' => fn (): array => $this->demo($user),
        ];
    }

    /**
     * The hosted demo, for its sandbox bar and "View as" switch: whether the demo is hosted and, for someone in a
     * sandbox, when it ends, the signed link back into it, and its people with their sample-team roles, the one
     * signed in marked current. The visitor comes first.
     *
     * @return array{hosted: bool, expires_at: string|null, resume_url: string|null, personas: list<array{id: int, name: string, roles: list<array{team: string, role: string}>, current: bool}>}
     */
    private function demo(?User $user): array
    {
        $sandbox = config('demo.hosted') ? $user?->demoSandbox : null;

        if ($user === null || $sandbox === null) {
            return ['hosted' => (bool) config('demo.hosted'), 'expires_at' => null, 'resume_url' => null, 'personas' => []];
        }

        $personas = array_values(User::query()
            ->where('demo_sandbox_id', $sandbox->id)
            ->with(['teams' => fn ($query) => $query->where('is_personal', false)->orderBy('teams.id')])
            ->orderByRaw('id = ? desc', [$sandbox->visitor_id])
            ->orderBy('id')
            ->get()
            ->map(fn (User $persona): array => [
                'id' => $persona->id,
                'name' => $persona->name,
                'roles' => array_values($persona->teams
                    ->map(fn (Team $team): array => [
                        'team' => $team->name,
                        'role' => TeamRole::from($team->getRelation('pivot')->getAttribute('role'))->label(),
                    ])
                    ->all()),
                'current' => $persona->is($user),
            ])
            ->all());

        return [
            'hosted' => true,
            'expires_at' => $sandbox->expires_at->toIso8601String(),
            'resume_url' => $sandbox->resumeUrl(),
            'personas' => $personas,
        ];
    }
}
