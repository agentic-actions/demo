<?php

use AgenticActions\OAuth\McpConnection;
use App\Enums\TokenAccess;
use App\Models\Task;
use App\Providers\AppServiceProvider;
use Tests\Support\Boards;
use Tests\Support\OAuthClient;
use Tests\Support\Sandboxes;

/*
 * MCP and OAuth on the hosted demo: a person holds at most five live tokens for a team, a sandbox's token ends with
 * its sandbox, MCP clients get 30 calls a minute per person, the device flow is not offered, and a visitor connects
 * Claude to their own sandbox's board and no one else's.
 */

beforeEach(function () {
    config(['demo.hosted' => true]);

    $this->sandbox = Sandboxes::start();
    $this->you = Sandboxes::person($this->sandbox, 'you');
    $this->acme = Sandboxes::team($this->sandbox, 'Acme');
    $this->globex = Sandboxes::team($this->sandbox, 'Globex');

    $this->mint = fn (string $name = 'Cursor') => $this->actingAs($this->you)
        ->post(route('teams.tokens.store', ['team' => $this->acme->slug]), ['name' => $name, 'access' => 'write']);
});

describe('tokens', function () {
    it('refuses a sixth live token for one team, counting neither expired tokens nor another team\'s', function () {
        foreach (range(1, 4) as $n) {
            $this->you->createToken("Client {$n}", TokenAccess::ReadWrite->abilities($this->acme), now()->addDay());
        }

        $this->you->createToken('Expired', TokenAccess::ReadWrite->abilities($this->acme), now()->subMinute());
        Sandboxes::person($this->sandbox, 'marcus')->createToken('Marcus\'s', TokenAccess::ReadWrite->abilities($this->acme));
        $this->you->createToken('Globex', TokenAccess::ReadWrite->abilities($this->globex));

        ($this->mint)('Fifth')->assertRedirect(route('teams.tokens.index', ['team' => $this->acme->slug]));

        ($this->mint)('Sixth')
            ->assertRedirect()
            ->assertSessionHasErrors(['name' => 'You have 5 tokens for this team, as many as the demo allows. Revoke one to make another.']);

        expect($this->you->tokens()->where('name', 'Sixth')->exists())->toBeFalse();

        $this->delete(route('teams.tokens.destroy', ['team' => $this->acme->slug, 'token' => $this->you->tokens()->where('name', 'Client 1')->value('id')]));

        ($this->mint)('Sixth')->assertSessionHasNoErrors();
    });

    it('has no cap on a local copy', function () {
        config(['demo.hosted' => false]);

        foreach (range(1, 5) as $n) {
            $this->you->createToken("Client {$n}", TokenAccess::ReadWrite->abilities($this->acme));
        }

        ($this->mint)()->assertSessionHasNoErrors();

        expect($this->you->tokens()->count())->toBe(6);
    });

    it('ends a sandbox\'s token when the sandbox ends', function () {
        $this->travel(3)->hours();

        ($this->mint)()->assertSessionHasNoErrors();

        expect($this->you->tokens()->sole()->expires_at->equalTo($this->sandbox->expires_at))->toBeTrue();
    });

    it('keeps the 90 days for someone outside any sandbox', function () {
        $boards = Boards::make();

        $this->actingAs($boards->member)
            ->post(route('teams.tokens.store', ['team' => 'acme']), ['name' => 'Cursor', 'access' => 'read'])
            ->assertSessionHasNoErrors();

        expect($boards->member->tokens()->sole()->expires_at->isSameDay(now()->addDays(90)))->toBeTrue();
    });
});

describe('MCP', function () {
    it('allows 30 calls a minute per person on the hosted demo', function () {
        expect(config('agentic-actions.mcp.per_minute'))->toBe(60);

        (new AppServiceProvider(app()))->boot();

        expect(config('agentic-actions.mcp.per_minute'))->toBe(30);

        $token = $this->you->createToken('Claude Code', TokenAccess::ReadWrite->abilities($this->acme))->plainTextToken;

        foreach (range(1, 30) as $call) {
            $this->postJson(Boards::mcpUrl($this->acme), Boards::rpc('tools/list'), Boards::bearer($token))->assertOk();
        }

        $this->postJson(Boards::mcpUrl($this->acme), Boards::rpc('tools/list'), Boards::bearer($token))->assertTooManyRequests();
    });
});

describe('OAuth', function () {
    it('does not offer the device flow', function () {
        $this->get('/oauth/device')->assertNotFound();

        $this->actingAs($this->you)->followingRedirects()->get('/oauth/device/authorize?user_code=ABCD-EFGH')->assertNotFound();
    });

    it('connects a visitor\'s Claude to their own Acme, from sign-in through a write', function () {
        $claude = new OAuthClient($this);

        // A browser with no session is sent to sign in, and the resume link brings it back to the consent screen.
        $consentUrl = '/oauth/authorize?'.http_build_query(['response_type' => 'code', 'client_id' => $claude->id, 'redirect_uri' => OAuthClient::REDIRECT, 'scope' => 'actions:read actions:write', 'code_challenge' => str_repeat('a', 43), 'code_challenge_method' => 'S256']);

        $this->get($consentUrl)->assertRedirect(route('login'));
        $back = $this->get($this->sandbox->resumeUrl())->assertRedirect()->headers->get('Location');

        expect($back)->toStartWith(url('/oauth/authorize?'))->toContain('client_id='.$claude->id);

        // That browser's session lives on in this one process, and Sanctum would read it on the MCP request below; a
        // real client sends only its bearer token.
        app('session.store')->flush();

        $consent = $claude->authorize($this->you, $this->acme)->assertOk();

        expect($consent->inertiaProps('tenant'))->toBe('Acme');

        $claude->token($claude->answer($consent->inertiaProps('approve')), $this->acme)->assertOk();

        expect(McpConnection::for($this->you)->sole()->tenant->is($this->acme))->toBeTrue();

        $claude->mcp($this->acme, 'tools/call', [
            'name' => 'create-task',
            'arguments' => ['title' => 'Written by Claude', 'assignee' => 'Marcus Reed', 'priority' => 'high', 'due_on' => now()->addWeek()->toDateString()],
        ])->assertOk()->assertJsonPath('result.isError', false);

        expect(Task::query()->where('title', 'Written by Claude')->sole()->team_id)->toBe($this->acme->id);
    });

    it('refuses consent for another sandbox\'s team', function () {
        $other = Sandboxes::start();
        $claude = new OAuthClient($this);

        $claude->authorize($this->you, Sandboxes::team($other, 'Acme'))->assertForbidden();

        expect(McpConnection::query()->count())->toBe(0);
    });
});
