<?php

namespace App\Http\Controllers\Teams;

use AgenticActions\OAuth\McpConnection;
use App\Http\Controllers\Controller;
use App\Models\Team;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Disconnects an app the signed-in person connected to this team's MCP URL with OAuth, from the "Connected apps" list
 * on the team's "AI clients" page.
 */
class TeamConnectionController extends Controller
{
    /**
     * Revoke the app's tokens, refresh tokens and unused codes for this person, and delete the connection. The next
     * call the app makes answers 401, and it asks the person to connect again. Anyone else's connection, or one to
     * another team, reads as not found.
     */
    public function destroy(Request $request, Team $team, string $connection): RedirectResponse
    {
        McpConnection::for($request->user())
            ->whereMorphedTo('tenant', $team)
            ->findOrFail((int) $connection)
            ->revoke();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('App disconnected.')]);

        return to_route('teams.tokens.index', ['team' => $team->slug]);
    }
}
