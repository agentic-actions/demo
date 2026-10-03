<?php

use Illuminate\Support\Facades\Route;
use Tests\Support\Boards;

/*
 * APP_PUBLIC_URL, for a short-lived tunnel to a remote MCP client: requests that arrive on the tunnel's host build
 * every URL on its https address, so the OAuth metadata names the URL the client entered, and never see a debug page.
 * Unset, the default, and on any other host, nothing changes.
 */

const TUNNEL = 'https://board-demo.trycloudflare.com';

beforeEach(function () {
    $this->boards = Boards::make();
});

it('builds the metadata and the challenge on the public URL for a request through the tunnel', function (string $url, array $headers) {
    config(['app.public_url' => TUNNEL]);

    $this->getJson($url.'/.well-known/oauth-protected-resource/mcp/t/acme', $headers)
        ->assertOk()
        ->assertJsonPath('resource', TUNNEL.'/mcp/t/acme')
        ->assertJsonPath('authorization_servers.0', TUNNEL);

    $this->getJson($url.'/.well-known/oauth-authorization-server', $headers)
        ->assertJsonPath('issuer', TUNNEL)
        ->assertJsonPath('authorization_endpoint', TUNNEL.'/oauth/authorize')
        ->assertJsonPath('registration_endpoint', TUNNEL.'/oauth/register');

    $challenge = $this->postJson($url.'/mcp/t/acme', Boards::rpc('tools/list'), $headers)
        ->assertUnauthorized()
        ->headers->get('WWW-Authenticate');

    expect($challenge)->toContain('resource_metadata="'.TUNNEL.'/.well-known/oauth-protected-resource/mcp/t/acme"');
})->with([
    'the host is the tunnel\'s, on an http origin (Herd copies X-Forwarded-Host)' => ['http://board-demo.trycloudflare.com', []],
    'the tunnel names it in X-Forwarded-Host (no Herd)' => ['http://agentic-actions-demo.test', ['X-Forwarded-Host' => 'board-demo.trycloudflare.com']],
]);

it('leaves every other request, and every request while it is unset, on its own host', function (?string $publicUrl, string $url, string $expected) {
    config(['app.public_url' => $publicUrl]);

    $this->getJson($url.'/.well-known/oauth-protected-resource/mcp/t/acme')
        ->assertJsonPath('resource', $expected.'/mcp/t/acme');
})->with([
    'set, a local request' => [TUNNEL, 'https://agentic-actions-demo.test', 'https://agentic-actions-demo.test'],
    'unset, a request on the tunnel host' => [null, 'http://board-demo.trycloudflare.com', 'http://board-demo.trycloudflare.com'],
]);

it('never shows a request through the tunnel a debug page', function () {
    config(['app.public_url' => TUNNEL, 'app.debug' => true]);
    Route::get('/boom', fn () => throw new RuntimeException('secret detail'));

    $this->get('https://agentic-actions-demo.test/boom')->assertServerError()->assertSee('secret detail');
    $this->get(TUNNEL.'/boom')->assertServerError()->assertDontSee('secret detail');
});
