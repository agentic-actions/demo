<?php

use AgenticActions\OAuth\McpConnection;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Boards;
use Tests\Support\OAuthClient;

/*
 * "Connected apps" on the team's "AI clients" page: the apps a person connected to the team's MCP URL with OAuth, and
 * Revoke, after which the app's access token and refresh token stop working. Leaving the team, or being removed from
 * it, revokes them too.
 */

beforeEach(function () {
    $this->boards = Boards::make();
});

it('lists only this person\'s connected apps for this team', function () {
    $boards = $this->boards;
    (new OAuthClient($this, 'Claude'))->connect($boards->member, $boards->acme, 'actions:read');
    (new OAuthClient($this, 'ChatGPT'))->connect($boards->member, $boards->globex);
    (new OAuthClient($this, 'Olivia\'s Claude'))->connect($boards->owner, $boards->acme);

    $this->actingAs($boards->member)
        ->get(route('teams.tokens.index', ['team' => 'acme']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('teams/tokens')
            ->where('connectorUrl', null)
            ->has('connections', 1)
            ->where('connections.0.client', 'Claude')
            ->where('connections.0.sends_to', 'claude.ai')
            ->where('connections.0.access', 'read')
            ->where('connections.0.access_label', 'Read only')
            ->whereNot('connections.0.connected_at', null)
        );
});

it('shows the connector URL on the public host while one is set', function () {
    config(['app.public_url' => 'https://board-demo.trycloudflare.com']);

    $this->actingAs($this->boards->member)
        ->get(route('teams.tokens.index', ['team' => 'acme']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('mcpUrl', url('/mcp/t/acme'))
            ->where('connectorUrl', 'https://board-demo.trycloudflare.com/mcp/t/acme')
        );
});

it('revokes a connected app: its access token and refresh token stop working', function () {
    $boards = $this->boards;
    $claude = (new OAuthClient($this))->connect($boards->owner, $boards->acme);
    $claude->mcp($boards->acme, 'tools/list')->assertOk();
    $connection = McpConnection::for($boards->owner)->sole();

    $this->actingAs($boards->owner)
        ->delete(route('teams.connections.destroy', ['team' => 'acme', 'connection' => $connection->id]))
        ->assertRedirect(route('teams.tokens.index', ['team' => 'acme']));

    $this->assertModelMissing($connection);
    $claude->mcp($boards->acme, 'tools/list')->assertUnauthorized();
    $claude->refresh()->assertStatus(400)->assertJsonPath('error', 'invalid_grant');
});

it('treats anyone else\'s connection, or one to another team, as not found', function () {
    $boards = $this->boards;
    (new OAuthClient($this))->connect($boards->owner, $boards->acme);
    (new OAuthClient($this))->connect($boards->member, $boards->globex);
    [$olivias, $globex] = [McpConnection::for($boards->owner)->sole(), McpConnection::for($boards->member)->sole()];

    $this->actingAs($boards->member)
        ->delete(route('teams.connections.destroy', ['team' => 'acme', 'connection' => $olivias->id]))
        ->assertNotFound();

    $this->actingAs($boards->member)
        ->delete(route('teams.connections.destroy', ['team' => 'acme', 'connection' => $globex->id]))
        ->assertNotFound();

    $this->assertModelExists($olivias);
    $this->assertModelExists($globex);
});

it('revokes a person\'s connected apps for a team they leave or are removed from', function () {
    $boards = $this->boards;
    $left = (new OAuthClient($this))->connect($boards->member, $boards->globex);
    $kept = (new OAuthClient($this))->connect($boards->member, $boards->acme);
    $removed = (new OAuthClient($this))->connect($boards->viewer, $boards->acme);

    $this->actingAs($boards->member)->delete(route('teams.leave', ['team' => 'globex']))->assertRedirect();
    $this->actingAs($boards->owner)->delete(route('teams.members.destroy', ['team' => 'acme', 'user' => $boards->viewer->id]))->assertRedirect();

    $left->mcp($boards->globex, 'tools/list')->assertUnauthorized();
    $removed->mcp($boards->acme, 'tools/list')->assertUnauthorized();
    $kept->mcp($boards->acme, 'tools/list')->assertOk();
    expect(McpConnection::query()->count())->toBe(1);
});
