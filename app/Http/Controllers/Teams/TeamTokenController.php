<?php

namespace App\Http\Controllers\Teams;

use AgenticActions\OAuth\McpConnection;
use App\Enums\TokenAccess;
use App\Http\Controllers\Controller;
use App\Http\Requests\Teams\CreateTeamTokenRequest;
use App\Models\Team;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Passport\Client;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * The team's "AI clients" page: personal access tokens that Claude Code, Cursor or Claude Desktop send as a bearer
 * header to the team's MCP URL, and the apps connected with OAuth (a Claude custom connector, ChatGPT) that the person
 * approved for this team's URL. A token belongs to the person who made it and is bound to this one team, so a client
 * reaches only what that person may do here. Nobody sees or revokes anyone else's.
 */
class TeamTokenController extends Controller
{
    /**
     * How long a new token lasts, in days.
     */
    private const LIFETIME_DAYS = 90;

    /**
     * Show the team's MCP URL, the signed-in person's connected apps and tokens for it, and the connector URL on the
     * public tunnel's host while app.public_url is set.
     */
    public function index(Request $request, Team $team): Response
    {
        $mcpUrl = route('agentic-actions.mcp.tenant', ['current_team' => $team->slug]);
        $publicUrl = config('app.public_url');

        return Inertia::render('teams/tokens', [
            'team' => ['name' => $team->name, 'slug' => $team->slug],
            'mcpUrl' => $mcpUrl,
            'connectorUrl' => is_string($publicUrl) && $publicUrl !== '' ? rtrim($publicUrl, '/').parse_url($mcpUrl, PHP_URL_PATH) : null,
            'connections' => McpConnection::for($request->user())
                ->whereMorphedTo('tenant', $team)
                ->whereHas('client')
                ->with('client')
                ->get()
                ->map(fn (McpConnection $connection): array => [
                    'id' => $connection->id,
                    'client' => $connection->client->name,
                    'sends_to' => self::sendsTo($connection->client),
                    'access' => TokenAccess::fromAbilities($connection->scopes)->value,
                    'access_label' => TokenAccess::fromAbilities($connection->scopes)->label(),
                    'connected_at' => $connection->created_at?->toISOString(),
                ]),
            'tokens' => $this->tokens($request->user(), $team)
                ->map(fn (PersonalAccessToken $token): array => [
                    'id' => $token->id,
                    'name' => $token->name,
                    'access' => TokenAccess::of($token)->value,
                    'access_label' => TokenAccess::of($token)->label(),
                    'created_at' => $token->created_at?->toISOString(),
                    'last_used_at' => $token->last_used_at?->toISOString(),
                    'expires_at' => $token->expires_at?->toISOString(),
                ])
                ->values(),
        ]);
    }

    /**
     * Mint a token for this team. Its plain text is flashed once and never stored: only its hash is kept.
     *
     * On the hosted demo a person holds at most demo.caps.tokens_per_user_team live tokens for one team, and a
     * sandbox's token ends when its sandbox does.
     */
    public function store(CreateTeamTokenRequest $request, Team $team): RedirectResponse
    {
        $user = $request->user();
        $cap = (int) config('demo.caps.tokens_per_user_team');
        $live = $this->tokens($user, $team)->reject(fn (PersonalAccessToken $token): bool => $token->expires_at?->isPast() ?? false);

        if (config('demo.hosted') && $live->count() >= $cap) {
            throw ValidationException::withMessages([
                'name' => __('You have :count tokens for this team, as many as the demo allows. Revoke one to make another.', ['count' => $cap]),
            ]);
        }

        $access = TokenAccess::from($request->validated('access'));
        $token = $user->createToken(
            $request->validated('name'),
            $access->abilities($team),
            $this->expiry($user),
        );

        Inertia::flash('token', ['name' => $token->accessToken->name, 'plain_text' => $token->plainTextToken]);

        return to_route('teams.tokens.index', ['team' => $team->slug]);
    }

    /**
     * Revoke one of the signed-in person's tokens for this team. Any other token reads as not found.
     */
    public function destroy(Request $request, Team $team, string $token): RedirectResponse
    {
        $found = $this->tokens($request->user(), $team)->firstWhere('id', (int) $token);

        abort_if($found === null, 404);

        $found->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Token revoked.')]);

        return to_route('teams.tokens.index', ['team' => $team->slug]);
    }

    /**
     * Where a client sends the person after they approve, as the consent screen names it: a website's host, or this
     * device for a loopback address or a desktop app's scheme.
     */
    private static function sendsTo(Client $client): string
    {
        $redirectUri = (string) Arr::first((array) $client->getAttribute('redirect_uris'));
        $scheme = parse_url($redirectUri, PHP_URL_SCHEME);
        $host = (string) parse_url($redirectUri, PHP_URL_HOST);

        return in_array($scheme, ['http', 'https'], true) && ! in_array($host, ['localhost', '127.0.0.1', '[::1]'], true)
            ? $host
            : __('an app on this device');
    }

    /**
     * When a new token ends: in 90 days, or when the person's sandbox does, if that comes first.
     */
    private function expiry(User $user): CarbonInterface
    {
        $expiresAt = now()->addDays(self::LIFETIME_DAYS);
        $sandboxEnds = $user->demoSandbox?->expires_at;

        return $sandboxEnds !== null && $sandboxEnds->lessThan($expiresAt) ? $sandboxEnds : $expiresAt;
    }

    /**
     * The person's tokens bound to this team, newest first.
     *
     * @return Collection<int, PersonalAccessToken>
     */
    private function tokens(User $user, Team $team): Collection
    {
        return $user->tokens()->latest('id')->get()
            ->filter(fn (PersonalAccessToken $token): bool => in_array(TokenAccess::teamAbility($team), $token->abilities ?? [], true));
    }
}
