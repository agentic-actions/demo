<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Boards;
use Tests\Support\Sandboxes;

/*
 * "View as": POST /demo/as/{persona} signs in as another person of the same sandbox (Marcus, Vera, Gus, or back as
 * the visitor), so a visitor sees each role's refusals and Globex's isolation for themselves. Never anyone else.
 */

beforeEach(function () {
    config(['demo.hosted' => true]);

    $this->sandbox = Sandboxes::start();
    $this->you = Sandboxes::person($this->sandbox, 'you');
    $this->acme = Sandboxes::team($this->sandbox, 'Acme');
});

it('signs in as a teammate on a new session id and opens their board', function () {
    $marcus = Sandboxes::person($this->sandbox, 'marcus');

    $response = $this->actingAs($this->you)
        ->withCookie((string) config('session.cookie'), Sandboxes::SESSION_ID)
        ->post(route('demo.as', ['persona' => $marcus->id]));

    $response->assertRedirect(route('board', ['current_team' => $this->acme->slug]));
    $this->assertAuthenticatedAs($marcus);

    expect(Sandboxes::sessionIdAfter($response))->not->toBeNull()->not->toBe(Sandboxes::SESSION_ID);
});

it('shows a viewer the refusals of a viewer, and switches back to the visitor', function () {
    $vera = Sandboxes::person($this->sandbox, 'vera');

    $this->actingAs($this->you)->post(route('demo.as', ['persona' => $vera->id]));
    $this->assertAuthenticatedAs($vera);

    $this->postJson(Boards::url('create-task', $this->acme), ['title' => 'Sneaky task'])
        ->assertForbidden()
        ->assertJsonPath('message', 'You are not allowed to do this.');

    $this->post(route('demo.as', ['persona' => $this->you->id]))
        ->assertRedirect(route('board', ['current_team' => $this->acme->slug]));
    $this->assertAuthenticatedAs($this->you);
});

it('opens Globex for Gus, who is kept out of Acme', function () {
    $gus = Sandboxes::person($this->sandbox, 'gus');

    $this->actingAs($this->you)
        ->post(route('demo.as', ['persona' => $gus->id]))
        ->assertRedirect(route('board', ['current_team' => Sandboxes::team($this->sandbox, 'Globex')->slug]));

    $this->get(route('board', ['current_team' => $this->acme->slug]))
        ->assertForbidden()
        ->assertInertia(fn (Assert $page) => $page
            ->component('forbidden')
            ->where('message', 'You are not a member of this team.')
            ->where('auth.user.id', $gus->id)
            ->where('demo.hosted', true));

    $this->postJson(Boards::url('update-task', $this->acme), [])
        ->assertForbidden()
        ->assertJsonPath('message', 'You are not a member of this team.');
});

it('clears the browser\'s encrypted page history on every switch, so Back cannot show the previous person\'s page', function () {
    $globex = Sandboxes::team($this->sandbox, 'Globex');

    // The host serves HTTPS, which the browser's crypto API needs.
    $board = fn (string $slug, string $scheme = 'https'): string => preg_replace('/^https?:/', "{$scheme}:", route('board', ['current_team' => $slug]));

    $before = $this->actingAs($this->you)->get($board($this->acme->slug))->inertiaPage();

    expect($before['encryptHistory'] ?? false)->toBeTrue()
        ->and($before['clearHistory'] ?? false)->toBeFalse();

    $this->post(route('demo.as', ['persona' => Sandboxes::person($this->sandbox, 'gus')->id]))->assertRedirect();

    $after = $this->get($board($globex->slug))->inertiaPage();

    expect($after['encryptHistory'] ?? false)->toBeTrue()
        ->and($after['clearHistory'] ?? false)->toBeTrue();

    // Over plain HTTP, as a local copy with DEMO_HOSTED is, encryption would stop every visit.
    expect($this->get($board($globex->slug, 'http'))->inertiaPage()['encryptHistory'] ?? false)->toBeFalse();

    config(['demo.hosted' => false]);

    expect($this->get($board($globex->slug))->inertiaPage()['encryptHistory'] ?? false)->toBeFalse();
});

it('refuses a person of another sandbox', function () {
    $stranger = Sandboxes::person(Sandboxes::start(), 'marcus');

    $this->actingAs($this->you)
        ->post(route('demo.as', ['persona' => $stranger->id]))
        ->assertForbidden();

    $this->assertAuthenticatedAs($this->you);
});

it('refuses anyone outside a sandbox, either way', function () {
    $outsider = User::factory()->create();

    $this->actingAs($outsider)
        ->post(route('demo.as', ['persona' => Sandboxes::person($this->sandbox, 'marcus')->id]))
        ->assertForbidden();
    $this->assertAuthenticatedAs($outsider);

    $this->actingAs($this->you)
        ->post(route('demo.as', ['persona' => $outsider->id]))
        ->assertForbidden();
    $this->assertAuthenticatedAs($this->you);
});

it('signs the visitor out instead once the sandbox has expired', function () {
    $this->travelTo($this->sandbox->expires_at->addSecond());

    $this->actingAs($this->you)
        ->post(route('demo.as', ['persona' => Sandboxes::person($this->sandbox, 'marcus')->id]))
        ->assertRedirect(route('home'));

    $this->assertGuest();
});

it('sends a guest to sign in', function () {
    $this->post(route('demo.as', ['persona' => $this->you->id]))->assertRedirect(route('login'));

    $this->assertGuest();
});

it('does not exist unless the demo is hosted', function () {
    config(['demo.hosted' => false]);

    $this->actingAs($this->you)
        ->post(route('demo.as', ['persona' => Sandboxes::person($this->sandbox, 'marcus')->id]))
        ->assertNotFound();

    $this->assertAuthenticatedAs($this->you);
});
