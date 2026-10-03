<?php

use App\Enums\TokenAccess;
use App\Models\DemoSandbox;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Boards;
use Tests\Support\OAuthClient;
use Tests\Support\Sandboxes;

/*
 * A sandbox ends at its expires_at, whether or not the prune has run yet (EndExpiredSandbox): its people are signed
 * out, its MCP tokens stop working, and "Try the demo" starts a new sandbox from the same browser.
 */

beforeEach(function () {
    config(['demo.hosted' => true]);

    $this->sandbox = Sandboxes::start();
    $this->you = Sandboxes::person($this->sandbox, 'you');
    $this->acme = Sandboxes::team($this->sandbox, 'Acme');
});

it('keeps a sandbox working until it expires', function () {
    $this->travelTo($this->sandbox->expires_at->subMinute());

    $this->actingAs($this->you)->get(route('board', ['current_team' => $this->acme->slug]))->assertOk();
    $this->assertAuthenticatedAs($this->you);
});

it('signs its people out of a page once it expires, and says why on the front page', function () {
    $this->actingAs($this->you)->get(route('board', ['current_team' => $this->acme->slug]))->assertOk();

    $this->travelTo($this->sandbox->expires_at->addMinute());

    $this->get(route('board', ['current_team' => $this->acme->slug]))
        ->assertRedirect(route('home'))
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'This sandbox has expired. Start a new one.']);

    $this->assertGuest();

    // The front page then offers "Try the demo", not the expired board.
    $this->get(route('home'))->assertOk()->assertInertia(fn (Assert $page) => $page->where('auth.user', null));
});

it('refuses a change to the board after expiry', function () {
    $this->travelTo($this->sandbox->expires_at->addMinute());

    $this->actingAs($this->you)
        ->postJson(Boards::url('create-task', $this->acme), ['title' => 'Written after the end'])
        ->assertUnauthorized()
        ->assertExactJson(['message' => 'This sandbox has expired. Start a new one.']);

    expect($this->acme->tasks()->where('title', 'Written after the end')->exists())->toBeFalse();
});

it('starts a new sandbox for the same browser', function () {
    $this->travelTo($this->sandbox->expires_at->addMinute());

    $response = $this->actingAs($this->you)->post(route('demo.store'));

    $new = DemoSandbox::latest('id')->first();

    expect($new->id)->not->toBe($this->sandbox->id);

    $response->assertRedirect(route('board', ['current_team' => Sandboxes::team($new, 'Acme')->slug]));
    $this->assertAuthenticatedAs(Sandboxes::person($new, 'you'));
});

it('stops its MCP tokens, Sanctum and OAuth alike, before the prune runs', function () {
    // Half an hour before the end, so the hour-long OAuth access token is still good after it.
    $this->travelTo($this->sandbox->expires_at->subMinutes(30));

    $sanctum = $this->you->createToken('Claude Code', TokenAccess::ReadWrite->abilities($this->acme), now()->addDays(90))->plainTextToken;
    $claude = (new OAuthClient($this))->connect($this->you, $this->acme);
    app('session.store')->flush();
    app('auth')->forgetGuards();

    $this->postJson(Boards::mcpUrl($this->acme), Boards::rpc('tools/list'), Boards::bearer($sanctum))->assertOk();
    app('auth')->forgetGuards();
    $claude->mcp($this->acme, 'tools/list')->assertOk();

    $this->travelTo($this->sandbox->expires_at->addMinute());
    app('auth')->forgetGuards();

    $this->postJson(Boards::mcpUrl($this->acme), Boards::rpc('tools/list'), Boards::bearer($sanctum))
        ->assertUnauthorized()
        ->assertExactJson(['message' => 'This sandbox has expired. Start a new one.']);

    app('auth')->forgetGuards();

    $claude->mcp($this->acme, 'tools/list')
        ->assertUnauthorized()
        ->assertExactJson(['message' => 'This sandbox has expired. Start a new one.']);
});

it('leaves people outside any sandbox alone', function () {
    $boards = Boards::make();

    $this->travel(30)->days();

    $this->actingAs($boards->member)->get(route('board', ['current_team' => 'acme']))->assertOk();
});
