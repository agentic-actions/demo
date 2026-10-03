<?php

use App\Models\DemoSandbox;
use Tests\Support\Sandboxes;

/*
 * The resume link: a signed URL that expires with the sandbox and signs its visitor back in from any browser, such as
 * the one an MCP client opens for OAuth consent. Nothing else about it is guessable or reusable past the sandbox.
 */

beforeEach(function () {
    config(['demo.hosted' => true]);

    $this->sandbox = Sandboxes::start();
});

it('signs the visitor back in on a new session id and opens the Acme board', function () {
    $response = $this->withCookie((string) config('session.cookie'), Sandboxes::SESSION_ID)->get($this->sandbox->resumeUrl());

    $response->assertRedirect(route('board', ['current_team' => Sandboxes::team($this->sandbox, 'Acme')->slug]));
    $this->assertAuthenticatedAs(Sandboxes::person($this->sandbox, 'you'));

    expect(Sandboxes::sessionIdAfter($response))->not->toBeNull()->not->toBe(Sandboxes::SESSION_ID);
});

it('signs in as the visitor even while viewing as a teammate', function () {
    $this->actingAs(Sandboxes::person($this->sandbox, 'gus'))
        ->get($this->sandbox->resumeUrl())
        ->assertRedirect();

    $this->assertAuthenticatedAs(Sandboxes::person($this->sandbox, 'you'));
});

it('returns to the page that asked for a sign-in, such as OAuth consent', function () {
    $consent = url('/oauth/authorize?client_id=abc&response_type=code');

    $this->withSession(['url.intended' => $consent])
        ->get($this->sandbox->resumeUrl())
        ->assertRedirect($consent);
});

it('expires with the sandbox', function () {
    $url = $this->sandbox->resumeUrl();

    $this->travelTo($this->sandbox->expires_at->addSecond());

    $this->get($url)->assertForbidden();
    $this->assertGuest();
});

it('refuses a tampered link', function (Closure $tamper) {
    $other = Sandboxes::start();

    $this->get($tamper($this->sandbox, $other))->assertForbidden();
    $this->assertGuest();
})->with([
    'another sandbox in the path' => fn (DemoSandbox $sandbox, DemoSandbox $other): string => str_replace("/demo/resume/{$sandbox->id}?", "/demo/resume/{$other->id}?", $sandbox->resumeUrl()),
    'a later expiry' => fn (DemoSandbox $sandbox): string => preg_replace('/expires=\d+/', 'expires='.now()->addYear()->getTimestamp(), $sandbox->resumeUrl()),
    'no signature' => fn (DemoSandbox $sandbox): string => route('demo.resume', ['sandbox' => $sandbox->id]),
    'an added parameter' => fn (DemoSandbox $sandbox): string => $sandbox->resumeUrl().'&as=1',
]);

it('answers 404 once the sandbox is gone', function () {
    $url = $this->sandbox->resumeUrl();

    $this->sandbox->delete();

    $this->get($url)->assertNotFound();
    $this->assertGuest();
});

it('does not exist unless the demo is hosted', function () {
    config(['demo.hosted' => false]);

    $this->get($this->sandbox->resumeUrl())->assertNotFound();
    $this->assertGuest();
});
