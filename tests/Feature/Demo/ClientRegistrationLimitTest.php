<?php

use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;
use Laravel\Passport\Client;
use Tests\Support\OAuthClient;

/*
 * Dynamic client registration, POST oauth/register, which anyone may call: at most five redirect URIs, none longer
 * than 512 characters, a name of at most 100 (LimitClientRegistration), and ten registrations a minute and 30 a day
 * from one address, 500 a day from all of them; Anthropic's range gets 120 a minute and 500 a day of its own.
 */

beforeEach(function () {
    $this->register = fn (array $body, string $address = '203.0.113.20'): TestResponse => $this
        ->withServerVariables(['REMOTE_ADDR' => $address])
        ->postJson('/oauth/register', [
            'client_name' => 'Claude',
            'redirect_uris' => [OAuthClient::REDIRECT],
            'grant_types' => ['authorization_code', 'refresh_token'],
            'token_endpoint_auth_method' => 'none',
            ...$body,
        ]);

    // A redirect URI on Claude's domain, padded to a length.
    $this->uriOf = fn (int $length): string => str_pad(OAuthClient::REDIRECT.'?pad=', $length, 'x');
});

describe('the body', function () {
    it('refuses more than five redirect URIs', function () {
        ($this->register)(['redirect_uris' => array_map(fn (int $n): string => OAuthClient::REDIRECT."?n={$n}", range(1, 6))])
            ->assertStatus(400)
            ->assertExactJson(['error' => 'invalid_redirect_uri', 'error_description' => 'A client may register at most 5 redirect URIs.']);

        expect(Client::count())->toBe(0);
    });

    it('refuses a redirect URI longer than 512 characters', function () {
        ($this->register)(['redirect_uris' => [($this->uriOf)(600)]])
            ->assertStatus(400)
            ->assertJsonPath('error', 'invalid_redirect_uri');

        ($this->register)(['redirect_uris' => [OAuthClient::REDIRECT, ($this->uriOf)(513)]])->assertStatus(400);

        expect(Client::count())->toBe(0);
    });

    it('refuses a client name longer than 100 characters, under either field', function (string $field) {
        ($this->register)(['client_name' => null, $field => str_repeat('a', 101)])
            ->assertStatus(400)
            ->assertExactJson(['error' => 'invalid_client_metadata', 'error_description' => 'A client name may be at most 100 characters.']);

        expect(Client::count())->toBe(0);
    })->with(['client_name', 'name']);

    it('registers a client at every bound', function () {
        $uris = array_map(fn (int $n): string => substr_replace(($this->uriOf)(512), (string) $n, -1), range(1, 5));

        ($this->register)(['client_name' => str_repeat('a', 100), 'redirect_uris' => $uris])->assertCreated();

        expect(Client::sole()->redirect_uris)->toBe($uris);
    });

    it('leaves the controller\'s own checks in place', function () {
        ($this->register)(['redirect_uris' => ['https://attacker.example/callback']])
            ->assertStatus(400)
            ->assertJsonPath('error', 'invalid_redirect_uri');
    });
});

describe('the rate', function () {
    it('allows ten registrations a minute from one address, and another address goes on', function () {
        foreach (range(1, 10) as $attempt) {
            ($this->register)([])->assertCreated();
        }

        ($this->register)([])->assertTooManyRequests();
        ($this->register)([], '198.51.100.30')->assertCreated();

        expect(Client::count())->toBe(11);
    });

    it('allows 120 a minute from Anthropic\'s range, where every Claude.ai connector registers', function () {
        foreach (range(1, 11) as $attempt) {
            ($this->register)([], '160.79.104.10')->assertCreated();
        }

        foreach (range(12, 120) as $attempt) {
            RateLimiter::hit(md5('oauth-register'.'ip:160.79.104.10'));
        }

        ($this->register)([], '160.79.104.10')->assertTooManyRequests();
    });

    it('allows 500 a day outside Anthropic\'s range, and the range keeps a day of its own', function () {
        foreach (range(1, 500) as $attempt) {
            RateLimiter::hit(md5('oauth-register'.'day:others'), 86400);
        }

        ($this->register)([], '198.51.100.40')->assertTooManyRequests();

        expect(Client::count())->toBe(0);

        ($this->register)([], '160.79.104.10')->assertCreated();
    });

    it('allows Anthropic\'s range 500 a day', function () {
        foreach (range(1, 500) as $attempt) {
            RateLimiter::hit(md5('oauth-register'.'day:claude'), 86400);
        }

        ($this->register)([], '160.79.104.10')->assertTooManyRequests();
        ($this->register)([], '198.51.100.40')->assertCreated();
    });

    it('allows one address 30 a day, so junk from it cannot lock Claude.ai\'s connectors out', function () {
        foreach (range(1, 30) as $attempt) {
            ($this->register)(['redirect_uris' => ['https://evil.example/cb']], '203.0.113.50')->assertStatus(400);

            if ($attempt % 10 === 0) {
                $this->travel(61)->seconds();
            }
        }

        ($this->register)([], '203.0.113.50')
            ->assertTooManyRequests()
            ->assertExactJson(['message' => 'Too many tries. Try again in about 24 hours.']);

        ($this->register)([], '160.79.104.10')->assertCreated();
        ($this->register)([], '198.51.100.9')->assertCreated();
    });

    it('counts a refused body against the address too', function () {
        foreach (range(1, 10) as $attempt) {
            ($this->register)(['client_name' => str_repeat('a', 101)])->assertStatus(400);
        }

        ($this->register)([])->assertTooManyRequests();
    });
});
