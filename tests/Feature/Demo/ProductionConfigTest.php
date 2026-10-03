<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\Support\Environment;

/*
 * The hosted demo as the host boots it: APP_ENV=production and DEMO_HOSTED=true in the process environment, read when
 * the app starts. Inertia's devtools register no routes, even when INERTIA_DEVTOOLS_ENABLED says otherwise; the kit's
 * sign-up, reset and two-factor pages answer 404; an MCP URL without a token answers 401 with its metadata; and the
 * app answers only to APP_URL's host. The SQLite pragmas and the SSR switch come from the environment.
 */

describe('booted as the host', function () {
    beforeEach(function () {
        Environment::with(
            ['APP_ENV' => 'production', 'DEMO_HOSTED' => 'true', 'INERTIA_DEVTOOLS_ENABLED' => 'true'],
            fn () => $this->refreshApplication(),
        );

        $this->usePassportKeys();
    });

    it('runs in production with the demo hosted', function () {
        expect(app()->isProduction())->toBeTrue()
            ->and(config('demo.hosted'))->toBeTrue();
    });

    it('registers no devtools routes', function () {
        $devtools = collect(Route::getRoutes()->getRoutes())->filter(fn ($route): bool => str_starts_with($route->uri(), '_inertia'));

        expect(config('inertia.devtools.enabled'))->toBeFalse()
            ->and($devtools)->toBeEmpty();

        $this->get('/_inertia/devtools/entries')->assertNotFound();
    });

    it('answers 404 to the kit\'s account pages', function (string $uri) {
        $this->get($uri)->assertNotFound();
    })->with([
        'sign-up' => '/register',
        'reset request' => '/forgot-password',
        'reset' => '/reset-password/some-token',
        'two-factor challenge' => '/two-factor-challenge',
        'passkey endpoints' => '/.well-known/passkey-endpoints',
    ]);

    it('answers an MCP request without a token with 401 and the metadata URL', function () {
        $this->postJson('/mcp/t/acme', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize'])
            ->assertUnauthorized()
            ->assertHeader('WWW-Authenticate');

        expect($this->postJson('/mcp/t/acme')->headers->get('WWW-Authenticate'))->toContain('resource_metadata=');
    });

    it('keeps a visitor\'s session as long as their sandbox, whatever SESSION_LIFETIME says', function () {
        expect(config('session.lifetime'))->toBe(24 * 60);
    });

    it('answers only to its own host', function () {
        $this->get('/login')->assertOk();

        $this->get('http://attacker.example/login')->assertBadRequest();
    });
});

describe('on a local copy', function () {
    it('trusts any host', function () {
        $this->get('http://attacker.example/login')->assertOk();
    });

    it('keeps SESSION_LIFETIME as it is', function () {
        $lifetime = Environment::with(['SESSION_LIFETIME' => '120'], function (): int {
            $this->refreshApplication();

            return config('session.lifetime');
        });

        expect($lifetime)->toBe(120);
    });
});

describe('settings from the environment', function () {
    it('reads the SQLite pragmas the host sets', function () {
        $sqlite = Environment::with(
            ['DB_BUSY_TIMEOUT' => '5000', 'DB_JOURNAL_MODE' => 'wal', 'DB_SYNCHRONOUS' => 'normal', 'DB_TRANSACTION_MODE' => 'IMMEDIATE'],
            fn (): array => (require config_path('database.php'))['connections']['sqlite'],
        );

        expect($sqlite)->toMatchArray([
            'busy_timeout' => '5000',
            'journal_mode' => 'wal',
            'synchronous' => 'normal',
            'transaction_mode' => 'IMMEDIATE',
        ]);
    });

    it('begins SQLite transactions IMMEDIATE unless told otherwise, so two writers wait instead of one failing', function () {
        $sqlite = (require config_path('database.php'))['connections']['sqlite'];

        expect(getenv('DB_TRANSACTION_MODE'))->toBeFalse()
            ->and($sqlite['transaction_mode'])->toBe('IMMEDIATE')
            ->and(DB::connection('sqlite')->getConfig('transaction_mode'))->toBe('IMMEDIATE');
    });

    it('turns server-side rendering on only when asked', function (string $value, bool $enabled) {
        $ssr = Environment::with(['INERTIA_SSR_ENABLED' => $value], fn (): array => (require config_path('inertia.php'))['ssr']);

        expect($ssr['enabled'])->toBe($enabled);
    })->with([
        'off' => ['false', false],
        'on' => ['true', true],
    ]);
});
