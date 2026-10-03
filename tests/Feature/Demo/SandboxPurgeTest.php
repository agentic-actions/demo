<?php

use AgenticActions\OAuth\McpConnection;
use App\Actions\Teams\CreateTeam;
use App\Enums\TokenAccess;
use App\Models\DemoSandbox;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Passport\ClientRepository;
use Tests\Support\Boards;
use Tests\Support\OAuthClient;
use Tests\Support\Sandboxes;

/*
 * demo:prune-sandboxes deletes an expired sandbox and every row keyed by its people or its teams, in every table that
 * holds one, and leaves a live sandbox as it was. A token the visitor gave an MCP client stops working with it.
 */

beforeEach(function () {
    config(['demo.hosted' => true, 'session.driver' => 'database']);

    /**
     * Use a sandbox the way a visitor would: an MCP token, Claude connected over OAuth, a copilot turn that keeps a
     * table, a team made and then deleted, an invitation, a passkey, a device code, a password reset row and a session.
     *
     * @return array{sanctum: string, claude: OAuthClient}
     */
    $this->use = function (DemoSandbox $sandbox): array {
        $you = Sandboxes::person($sandbox, 'you');
        $acme = Sandboxes::team($sandbox, 'Acme');

        $sanctum = $you->createToken('Claude Code', TokenAccess::ReadWrite->abilities($acme), $sandbox->expires_at)->plainTextToken;
        $claude = (new OAuthClient($this, 'Claude '.$sandbox->id))->connect($you, $acme);
        app('session.store')->flush();

        $this->actingAs($you)->postJson(route('assistant', ['current_team' => $acme->slug]), [
            'id' => 'assistant',
            'messages' => [['id' => 'm1', 'role' => 'user', 'parts' => [['type' => 'text', 'text' => 'Tasks by status']]]],
            'trigger' => 'submit-message',
            'page' => ['url' => "/{$acme->slug}/board", 'component' => 'board'],
        ], ['Accept' => 'application/json, text/event-stream'])->assertOk()->streamedContent();

        app(CreateTeam::class)->handle($you, 'Side project')->delete();

        TeamInvitation::factory()->create(['team_id' => $acme->id, 'invited_by' => $you->id]);

        DB::table('passkeys')->insert(['user_id' => $you->id, 'name' => 'Laptop', 'credential_id' => Str::random(20), 'credential' => '{}']);
        DB::table('oauth_device_codes')->insert(['id' => Str::random(40), 'user_id' => $you->id, 'client_id' => $claude->id, 'user_code' => Str::random(8), 'scopes' => '[]', 'revoked' => false]);
        DB::table('password_reset_tokens')->insert(['email' => $you->email, 'token' => Str::random(40)]);
        DB::table('sessions')->insert(['id' => Str::random(40), 'user_id' => $you->id, 'payload' => '', 'last_activity' => now()->timestamp]);

        return ['sanctum' => $sanctum, 'claude' => $claude];
    };

    /**
     * The ids a sandbox's rows are keyed by, read while it still exists: its people, their emails, its teams (deleted
     * ones too), its people's OAuth access tokens and their copilot conversations.
     *
     * @return array{sandbox: int, users: list<int>, emails: list<string>, teams: list<int>, tokens: list<string>, conversations: list<string>}
     */
    $this->keysOf = function (DemoSandbox $sandbox): array {
        $userIds = User::query()->where('demo_sandbox_id', $sandbox->id)->pluck('id');

        return [
            'sandbox' => $sandbox->id,
            'users' => $userIds->all(),
            'emails' => User::query()->whereIn('id', $userIds)->pluck('email')->all(),
            'teams' => Team::withTrashed()->where('demo_sandbox_id', $sandbox->id)->pluck('id')->all(),
            'tokens' => DB::table('oauth_access_tokens')->whereIn('user_id', $userIds)->pluck('id')->all(),
            'conversations' => DB::table('agent_conversations')->where('participant_type', (new User)->getMorphClass())->whereIn('participant_id', $userIds)->pluck('id')->all(),
        ];
    };

    /**
     * How many rows each table holds for those ids.
     *
     * @param  array{sandbox: int, users: list<int>, emails: list<string>, teams: list<int>, tokens: list<string>, conversations: list<string>}  $keys
     * @return array<string, int>
     */
    $this->footprint = function (array $keys): array {
        $user = (new User)->getMorphClass();
        $team = (new Team)->getMorphClass();
        [$userIds, $teamIds, $tokenIds, $conversationIds] = [$keys['users'], $keys['teams'], $keys['tokens'], $keys['conversations']];
        $byPerson = fn (string $table, string $morph = 'participant') => DB::table($table)->where("{$morph}_type", $user)->whereIn("{$morph}_id", $userIds)->count();
        $byTeam = fn (string $table) => DB::table($table)->where('tenant_type', $team)->whereIn('tenant_id', $teamIds)->count();

        return [
            'demo_sandboxes' => DB::table('demo_sandboxes')->where('id', $keys['sandbox'])->count(),
            'users' => DB::table('users')->whereIn('id', $userIds)->orWhere('demo_sandbox_id', $keys['sandbox'])->count(),
            'teams' => DB::table('teams')->whereIn('id', $teamIds)->orWhere('demo_sandbox_id', $keys['sandbox'])->count(),
            'teams (deleted)' => DB::table('teams')->whereIn('id', $teamIds)->whereNotNull('deleted_at')->count(),
            'team_members' => DB::table('team_members')->whereIn('team_id', $teamIds)->orWhereIn('user_id', $userIds)->count(),
            'team_invitations' => DB::table('team_invitations')->whereIn('team_id', $teamIds)->orWhereIn('invited_by', $userIds)->count(),
            'projects' => DB::table('projects')->whereIn('team_id', $teamIds)->count(),
            'tasks' => DB::table('tasks')->whereIn('team_id', $teamIds)->count(),
            'passkeys' => DB::table('passkeys')->whereIn('user_id', $userIds)->count(),
            'sessions' => DB::table('sessions')->whereIn('user_id', $userIds)->count(),
            'password_reset_tokens' => DB::table('password_reset_tokens')->whereIn('email', $keys['emails'])->count(),
            'personal_access_tokens' => $byPerson('personal_access_tokens', 'tokenable'),
            'oauth_access_tokens' => DB::table('oauth_access_tokens')->whereIn('id', $tokenIds)->orWhereIn('user_id', $userIds)->count(),
            'oauth_refresh_tokens' => DB::table('oauth_refresh_tokens')->whereIn('access_token_id', $tokenIds)->count(),
            'oauth_auth_codes' => DB::table('oauth_auth_codes')->whereIn('user_id', $userIds)->count(),
            'oauth_device_codes' => DB::table('oauth_device_codes')->whereIn('user_id', $userIds)->count(),
            'agentic_mcp_connections' => $byPerson('agentic_mcp_connections', 'user') + $byTeam('agentic_mcp_connections'),
            'agent_conversations' => DB::table('agent_conversations')->whereIn('id', $conversationIds)->count() + $byPerson('agent_conversations'),
            'agent_conversation_messages' => DB::table('agent_conversation_messages')->whereIn('conversation_id', $conversationIds)->count() + $byPerson('agent_conversation_messages'),
            'agentic_conversations' => $byPerson('agentic_conversations') + $byTeam('agentic_conversations'),
            'agentic_views' => $byPerson('agentic_views') + $byTeam('agentic_views'),
        ];
    };
});

afterEach(function () {
    ignore_user_abort(false);
});

it('deletes an expired sandbox from every table, leaves a live one as it was, and its MCP tokens stop working', function () {
    $expired = Sandboxes::start();
    $old = ($this->use)($expired);
    $oldAcme = Sandboxes::team($expired, 'Acme');
    $keys = ($this->keysOf)($expired);
    $used = ($this->footprint)($keys);

    // Every table the purge covers holds something of this sandbox, so an empty one afterwards means a deletion.
    expect(collect($used)->filter(fn (int $rows): bool => $rows === 0)->keys()->all())->toBe([]);

    $this->travel(25)->hours();

    $live = Sandboxes::start();
    $new = ($this->use)($live);
    $liveAcme = Sandboxes::team($live, 'Acme');
    $liveKeys = ($this->keysOf)($live);
    $kept = ($this->footprint)($liveKeys);

    $this->artisan('demo:prune-sandboxes')->expectsOutputToContain('Deleted 1 expired sandbox.')->assertSuccessful();

    expect(($this->footprint)($keys))->toBe(array_map(fn (): int => 0, $used))
        ->and(($this->footprint)($liveKeys))->toBe($kept)
        ->and(DemoSandbox::query()->pluck('id')->all())->toBe([$live->id]);

    app('auth')->forgetGuards();
    $this->postJson(Boards::mcpUrl($oldAcme), Boards::rpc('tools/list'), Boards::bearer($old['sanctum']))->assertUnauthorized();
    $old['claude']->mcp($oldAcme, 'tools/list')->assertUnauthorized();
    $old['claude']->refresh()->assertStatus(400);

    app('auth')->forgetGuards();
    $this->postJson(Boards::mcpUrl($liveAcme), Boards::rpc('tools/list'), Boards::bearer($new['sanctum']))->assertOk();
    $new['claude']->mcp($liveAcme, 'tools/list')->assertOk();
});

it('leaves a sandbox with time left, and does nothing when none has expired', function () {
    $sandbox = Sandboxes::start();
    $keys = ($this->keysOf)($sandbox);
    $before = ($this->footprint)($keys);

    $this->travel(23)->hours();

    $this->artisan('demo:prune-sandboxes')->expectsOutputToContain('Deleted 0 expired sandboxes.')->assertSuccessful();

    expect(($this->footprint)($keys))->toBe($before);
});

it('waits five minutes past expiry, so a copilot turn started just before cannot leave messages behind', function () {
    $sandbox = Sandboxes::start();

    $this->travelTo($sandbox->expires_at->addMinutes(4));
    $this->artisan('demo:prune-sandboxes')->expectsOutputToContain('Deleted 0 expired sandboxes.')->assertSuccessful();

    expect(DemoSandbox::count())->toBe(1);

    $this->travelTo($sandbox->expires_at->addMinutes(5));
    $this->artisan('demo:prune-sandboxes')->expectsOutputToContain('Deleted 1 expired sandbox.')->assertSuccessful();

    expect(DemoSandbox::count())->toBe(0);
});

it('signs out a visitor whose sandbox was deleted', function () {
    $sandbox = Sandboxes::start();
    $you = Sandboxes::person($sandbox, 'you');
    $acme = Sandboxes::team($sandbox, 'Acme');

    $this->actingAs($you)->get("/{$acme->slug}/board")->assertOk();

    $this->travel(25)->hours();
    $this->artisan('demo:prune-sandboxes')->assertSuccessful();

    app('auth')->forgetGuards();
    $this->get("/{$acme->slug}/board")->assertRedirect(route('login'));
});

it('runs every fifteen minutes, one run at a time, beside the daily token and client prunes', function () {
    $events = collect(app(Schedule::class)->events())->keyBy(fn (Event $event): string => Str::after((string) $event->command, 'artisan\' '));

    expect($events['demo:prune-sandboxes']->expression)->toBe('*/15 * * * *')
        ->and($events['demo:prune-sandboxes']->withoutOverlapping)->toBeTrue()
        ->and($events['demo:prune-sandboxes']->expiresAt)->toBe(10)
        ->and($events['demo:prune-clients']->expression)->toBe('0 0 * * *')
        ->and($events['passport:purge']->expression)->toBe('0 0 * * *')
        ->and($events['sanctum:prune-expired --hours=24']->expression)->toBe('0 0 * * *');
});

describe('expired cache rows', function () {
    beforeEach(function () {
        $this->cachePrune = collect(app(Schedule::class)->events())->sole(fn (Event $event): bool => $event->description === 'Delete expired cache rows');
    });

    it('deletes them daily under the database store, which never does on its own, and keeps the live ones', function () {
        config(['cache.default' => 'database']);

        DB::table('cache')->insert([
            ['key' => 'limiter-gone', 'value' => 'i:1;', 'expiration' => now()->subMinute()->getTimestamp()],
            ['key' => 'limiter-live', 'value' => 'i:1;', 'expiration' => now()->addMinute()->getTimestamp()],
        ]);

        expect($this->cachePrune->expression)->toBe('0 0 * * *')
            ->and($this->cachePrune->filtersPass(app()))->toBeTrue();

        $this->cachePrune->run(app());

        expect(DB::table('cache')->pluck('key')->all())->toBe(['limiter-live']);
    });

    it('does not run under Redis, which expires its own keys', function () {
        config(['cache.default' => 'redis']);

        expect($this->cachePrune->filtersPass(app()))->toBeFalse();
    });
});

describe('demo:prune-clients', function () {
    it('deletes a client registered over a day ago that nobody connected, and keeps the rest', function () {
        $sandbox = Sandboxes::start();
        $you = Sandboxes::person($sandbox, 'you');
        $acme = Sandboxes::team($sandbox, 'Acme');

        $connected = (new OAuthClient($this, 'Connected'))->connect($you, $acme);
        $abandoned = new OAuthClient($this, 'Abandoned');
        $abandoned->authorize($you, $acme)->assertOk();
        $confidential = app(ClientRepository::class)->createAuthorizationCodeGrantClient('Made by hand', [OAuthClient::REDIRECT]);

        $this->travel(25)->hours();

        $recent = new OAuthClient($this, 'Recent');

        $this->artisan('demo:prune-clients')->expectsOutputToContain('Deleted 1 unused OAuth client.')->assertSuccessful();

        expect(DB::table('oauth_clients')->pluck('name')->sort()->values()->all())->toBe(['Connected', 'Made by hand', 'Recent'])
            ->and(McpConnection::query()->sole()->client_id)->toBe($connected->id)
            ->and($confidential->exists)->toBeTrue();
    });

    it('keeps a client that still holds a live access token', function () {
        $boards = Boards::make();
        $claude = (new OAuthClient($this))->connect($boards->owner, $boards->acme);

        McpConnection::query()->delete();
        DB::table('oauth_access_tokens')->update(['expires_at' => now()->addDays(2)]);

        $this->travel(25)->hours();

        $this->artisan('demo:prune-clients')->expectsOutputToContain('Deleted 0 unused OAuth clients.')->assertSuccessful();

        expect(DB::table('oauth_clients')->where('id', $claude->id)->exists())->toBeTrue();
    });

    it('deletes an unused client\'s tokens and codes with it', function () {
        $boards = Boards::make();
        $claude = (new OAuthClient($this))->connect($boards->owner, $boards->acme);

        McpConnection::for($boards->owner)->sole()->revoke();

        $this->travel(25)->hours();

        $this->artisan('demo:prune-clients')->assertSuccessful();

        expect(DB::table('oauth_clients')->count())->toBe(0)
            ->and(DB::table('oauth_access_tokens')->count())->toBe(0)
            ->and(DB::table('oauth_refresh_tokens')->count())->toBe(0)
            ->and(DB::table('oauth_auth_codes')->where('client_id', $claude->id)->count())->toBe(0);
    });
});
