<?php

namespace Tests\Support;

use App\Actions\Demo\CreateSandbox;
use App\Models\DemoSandbox;
use App\Models\Team;
use App\Models\User;
use Illuminate\Testing\TestResponse;

/**
 * Hosted-demo sandboxes for tests: one made the way POST /demo makes it, its people by handle (you, marcus, vera,
 * gus), its sample teams by name, and the session id a browser holds, to show that a sign-in starts a new one.
 */
final class Sandboxes
{
    /**
     * A valid session id (40 letters and digits) for a browser that already had a session before signing in.
     */
    public const SESSION_ID = 'BrowserSessionIdBeforeSigningInXXXXXXXX1';

    /**
     * Start a sandbox as "Try the demo" does.
     */
    public static function start(): DemoSandbox
    {
        return app(CreateSandbox::class)->handle('203.0.113.7');
    }

    /**
     * One of the sandbox's people by email handle: you, marcus, vera or gus.
     */
    public static function person(DemoSandbox $sandbox, string $handle): User
    {
        return User::query()
            ->where('demo_sandbox_id', $sandbox->id)
            ->where('email', 'like', "{$handle}-%@sandbox.invalid")
            ->sole();
    }

    /**
     * One of the sandbox's sample teams by name: Acme or Globex.
     */
    public static function team(DemoSandbox $sandbox, string $name): Team
    {
        return Team::query()
            ->where('demo_sandbox_id', $sandbox->id)
            ->where('name', $name)
            ->where('is_personal', false)
            ->sole();
    }

    /**
     * The session id a response leaves the browser with, decrypted from its session cookie.
     */
    public static function sessionIdAfter(TestResponse $response): ?string
    {
        return $response->getCookie((string) config('session.cookie'))?->getValue();
    }
}
