<?php

use AgenticActions\OAuth\McpConnection;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Boards;
use Tests\Support\OAuthClient;

/*
 * The OAuth consent screen a remote MCP client (a Claude custom connector) sends the person to, as the app's Inertia
 * page: which app asks, where the answer goes, the team, and what it may do in plain words. Allow and Deny post to
 * Passport's routes as plain forms, and an approval connects the client to that one team's MCP URL.
 */

beforeEach(function () {
    $this->boards = Boards::make();
});

it('shows the client, where the answer goes, the team and the abilities in plain words', function () {
    $claude = new OAuthClient($this);

    $claude->authorize($this->boards->owner, $this->boards->acme)
        ->assertOk()
        ->assertHeader('X-Frame-Options', 'DENY')
        ->assertInertia(fn (Assert $page) => $page
            ->component('oauth/consent')
            ->where('app', config('app.name'))
            ->where('client', 'Claude')
            ->where('redirect', ['host' => 'claude.ai', 'local' => false])
            ->where('tenant', 'Acme')
            ->where('abilities', ['Read what you can see', 'Create and change what you can change'])
            ->where('person', 'owner@example.com')
            ->where('approve.url', route('passport.authorizations.approve'))
            ->where('approve.method', 'POST')
            ->where('approve.fields.client_id', $claude->id)
            ->where('approve.fields.state', 'st123')
            ->where('deny.fields._method', 'DELETE')
        );
});

it('connects the client to the team\'s MCP URL on Allow, and its token lists the team\'s tools', function () {
    $boards = $this->boards;
    $claude = new OAuthClient($this);
    $consent = $claude->authorize($boards->member, $boards->acme)->assertOk();

    $approval = $claude->answer($consent->inertiaProps('approve'));

    expect($approval->headers->get('Location'))->toStartWith(OAuthClient::REDIRECT.'?code=');

    $connection = McpConnection::for($boards->member)->sole();

    expect($connection->client_id)->toBe($claude->id)
        ->and($connection->tenant->is($boards->acme))->toBeTrue()
        ->and($connection->scopes)->toBe(['actions:read', 'actions:write']);

    $claude->token($approval, $boards->acme)->assertOk()->assertJsonPath('expires_in', 3600);

    expect(array_column($claude->mcp($boards->acme, 'tools/list')->assertOk()->json('result.tools'), 'name'))
        ->toContain('list-tasks', 'create-task')
        ->not->toContain('delete-task');

    // Marcus is in Globex too, but approved only Acme's URL: the token reaches nothing there.
    expect($claude->mcp($boards->globex, 'tools/list')->json('result.tools'))->toBe([]);
});

it('sends the person back to the client with access_denied on Deny, and connects nothing', function () {
    $claude = new OAuthClient($this);
    $consent = $claude->authorize($this->boards->owner, $this->boards->acme)->assertOk();

    $denial = $claude->answer($consent->inertiaProps('deny'));

    expect($denial->headers->get('Location'))->toStartWith(OAuthClient::REDIRECT.'?')->toContain('error=access_denied');
    expect(McpConnection::query()->count())->toBe(0);
});

it('asks a signed-out person to log in first', function () {
    $claude = new OAuthClient($this);

    $this->get('/oauth/authorize?'.http_build_query([
        'response_type' => 'code',
        'client_id' => $claude->id,
        'redirect_uri' => OAuthClient::REDIRECT,
        'scope' => 'actions:read',
        'code_challenge' => str_repeat('a', 43),
        'code_challenge_method' => 'S256',
        'resource' => Boards::mcpUrl($this->boards->acme),
    ]))->assertRedirect(route('login'));
});

it('refuses a team the person does not belong to', function () {
    $claude = new OAuthClient($this);

    $claude->authorize($this->boards->globexOwner, $this->boards->acme)->assertForbidden();

    expect(McpConnection::query()->count())->toBe(0);
});
