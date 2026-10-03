<?php

use App\Enums\TokenAccess;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\Support\Boards;

/*
 * The team's "AI clients" page, /settings/teams/{team}/tokens: a person's MCP tokens for one team. Each token names
 * its abilities and is bound to the team; it is shown once, listed with its last use, and revoked by its owner only.
 */

beforeEach(function () {
    $this->boards = Boards::make();
});

it('shows the team\'s MCP URL and only this person\'s tokens for this team', function () {
    $boards = $this->boards;
    $boards->member->createToken('Claude Code', TokenAccess::ReadWrite->abilities($boards->acme))->accessToken->forceFill(['last_used_at' => now()])->save();
    $boards->member->createToken('Globex client', TokenAccess::Read->abilities($boards->globex));
    $boards->member->createToken('Everything');
    $boards->owner->createToken('Olivia\'s client', TokenAccess::Read->abilities($boards->acme));

    $this->actingAs($boards->member)
        ->get(route('teams.tokens.index', ['team' => 'acme']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('teams/tokens')
            ->where('mcpUrl', url('/mcp/t/acme'))
            ->has('tokens', 1)
            ->where('tokens.0.name', 'Claude Code')
            ->where('tokens.0.access', 'write')
            ->where('tokens.0.access_label', 'Read and write')
            ->whereNot('tokens.0.last_used_at', null)
        );
});

it('mints a token bound to the team, with named abilities and an expiry, and flashes it once', function (string $access, array $abilities) {
    $boards = $this->boards;

    $response = $this->actingAs($boards->member)
        ->post(route('teams.tokens.store', ['team' => 'acme']), ['name' => 'Cursor', 'access' => $access])
        ->assertRedirect(route('teams.tokens.index', ['team' => 'acme']))
        ->assertInertiaFlash('token.name', 'Cursor');

    $token = $boards->member->tokens()->sole();
    $plainText = $response->baseResponse->getSession()->get('inertia.flash_data')['token']['plain_text'] ?? null;

    expect($token->name)->toBe('Cursor')
        ->and($token->abilities)->toBe(str_replace('{acme}', (string) $boards->acme->id, $abilities))
        ->and($token->expires_at->isSameDay(now()->addDays(90)))->toBeTrue()
        ->and(PersonalAccessToken::findToken((string) $plainText)?->is($token))->toBeTrue();
})->with([
    'read only' => ['read', ['actions:read', 'tenant:{acme}']],
    'read and write' => ['write', ['actions:read', 'actions:write', 'tenant:{acme}']],
]);

it('validates the name and the access', function () {
    $this->actingAs($this->boards->member)
        ->post(route('teams.tokens.store', ['team' => 'acme']), ['name' => '', 'access' => 'everything'])
        ->assertSessionHasErrors(['name', 'access']);

    expect(PersonalAccessToken::query()->count())->toBe(0);
});

it('keeps people out of a team they do not belong to', function () {
    $this->actingAs($this->boards->globexOwner)
        ->get(route('teams.tokens.index', ['team' => 'acme']))
        ->assertForbidden();

    $this->actingAs($this->boards->globexOwner)
        ->post(route('teams.tokens.store', ['team' => 'acme']), ['name' => 'Sneaky', 'access' => 'write'])
        ->assertForbidden();

    expect(PersonalAccessToken::query()->count())->toBe(0);
});

it('revokes the person\'s own token', function () {
    $boards = $this->boards;
    $token = $boards->member->createToken('Claude Code', TokenAccess::ReadWrite->abilities($boards->acme))->accessToken;

    $this->actingAs($boards->member)
        ->delete(route('teams.tokens.destroy', ['team' => 'acme', 'token' => $token->id]))
        ->assertRedirect(route('teams.tokens.index', ['team' => 'acme']));

    $this->assertModelMissing($token);
});

it('treats anyone else\'s token, or one bound to another team, as not found', function () {
    $boards = $this->boards;
    $olivias = $boards->owner->createToken('Olivia\'s client', TokenAccess::ReadWrite->abilities($boards->acme))->accessToken;
    $globex = $boards->member->createToken('Globex client', TokenAccess::ReadWrite->abilities($boards->globex))->accessToken;

    $this->actingAs($boards->member)
        ->delete(route('teams.tokens.destroy', ['team' => 'acme', 'token' => $olivias->id]))
        ->assertNotFound();

    $this->actingAs($boards->member)
        ->delete(route('teams.tokens.destroy', ['team' => 'acme', 'token' => $globex->id]))
        ->assertNotFound();

    $this->assertModelExists($olivias);
    $this->assertModelExists($globex);
});

it('links the page from the team settings', function () {
    $this->actingAs($this->boards->viewer)
        ->get(route('teams.edit', ['team' => 'acme']))
        ->assertOk();

    $this->actingAs($this->boards->viewer)
        ->get(route('teams.tokens.index', ['team' => 'acme']))
        ->assertOk();
});
