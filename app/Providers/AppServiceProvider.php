<?php

namespace App\Providers;

use AgenticActions\OAuth\Consent;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterval;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Inertia\ExceptionResponse;
use Inertia\Inertia;
use Laravel\Passport\Passport;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Response;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * Inertia's devtools record every request's props and serve them at /_inertia/devtools. They are on only in the
     * local environment by default; the hosted demo turns them off whatever INERTIA_DEVTOOLS_ENABLED says. This runs
     * here, before any provider boots, because the devtools register their routes at boot.
     *
     * A hosted visitor's session lasts at least as long as their sandbox, whatever SESSION_LIFETIME says: a session
     * that ended after two idle hours would leave someone who never copied the resume link no way back in.
     */
    public function register(): void
    {
        if (config('demo.hosted')) {
            config([
                'inertia.devtools.enabled' => false,
                'session.lifetime' => max((int) config('session.lifetime'), (int) config('demo.ttl_hours') * 60),
            ]);
        }
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureOAuth();
        $this->configureRateLimits();
        $this->configureHostedDemo();
        $this->configureErrorPages();
    }

    /**
     * A team page opened by someone outside the team (Gus on Acme's board) answers 403 with an Inertia page in the
     * app's layout, saying why and linking back to their own board, instead of the bare error page. JSON calls, and
     * refusals of anything but a page visit, keep their plain 403.
     */
    protected function configureErrorPages(): void
    {
        Inertia::handleExceptionsUsing(function (ExceptionResponse $response): ?ExceptionResponse {
            $request = $response->request;

            if ($response->statusCode() !== 403 || ! $request->isMethod('GET') || $request->expectsJson() || $request->route('current_team') === null || $request->user() === null) {
                return null;
            }

            $message = $response->exception->getMessage();

            return $response->render('forbidden', [
                'message' => $message !== '' && $message !== 'This action is unauthorized.' ? $message : __('You cannot open this page in this team.'),
            ])->withSharedData();
        });
    }

    /**
     * The hosted demo makes no outbound HTTP request: the assistant is scripted (config/ai.php), and the one other
     * caller, the password rule's breach check, sits behind sign-up, password reset and password change, which are
     * off there. A request that slips through throws instead of leaving the box. Inertia's SSR server, on this machine,
     * stays reachable in case it is ever turned on. MCP clients get 30 calls a minute per person there, not 60.
     */
    protected function configureHostedDemo(): void
    {
        if (! config('demo.hosted')) {
            return;
        }

        Http::preventStrayRequests();
        Http::allowStrayRequests([rtrim((string) config('inertia.ssr.url'), '/').'/*']);

        config(['agentic-actions.mcp.per_minute' => 30]);
    }

    /**
     * Named limiters, each with keys of its own, so one surface's traffic never spends another's allowance: the
     * throttle middleware hashes the limiter's name into every key.
     *
     * - "Try the demo" allows five sandboxes an hour from one address and 120 an hour in all (demo.starts_per_hour,
     *   which DemoController also checks against the sandboxes themselves), on top of the demo.max_live cap. The
     *   resume link and "View as" allow 30 a minute from one address.
     * - The assistant takes ten turns a minute per person and 30 per address, and on the hosted demo 300 a day per
     *   person, which bounds the conversation a sandbox keeps; its transcript, 30 reads a minute.
     * - The board's action routes keep two buckets per person: the change feed, 30 polls a minute (a board polls
     *   every five seconds while it is visible, so two open tabs use 24), and every other call, 60 a minute.
     * - Team, member, token and connection changes allow 20 a minute per person; minting a token, five.
     * - OAuth: the discovery documents allow 120 a minute per address. Client registration allows ten a minute per
     *   address, 120 from Anthropic's range (every Claude.ai connector registers from it), and 500 a day. The day
     *   counts Anthropic's range apart from everyone else, and allows any other address 30, so nobody outside the
     *   range can spend the day's registrations Claude.ai's connectors need. The
     *   consent screen and its answers allow 20 a minute per person, or per address before sign-in; it runs on
     *   Passport's routes (config/passport.php), whose other routes it leaves to Passport's own throttle.
     */
    protected function configureRateLimits(): void
    {
        RateLimiter::for('demo-start', fn (Request $request): array => [
            Limit::perHour((int) config('demo.starts_per_hour.address'))->by('ip:'.$request->ip()),
            Limit::perHour((int) config('demo.starts_per_hour.all'))->by('all'),
        ]);

        RateLimiter::for('demo-session', fn (Request $request): Limit => Limit::perMinute(30)->by('ip:'.$request->ip()));

        RateLimiter::for('assistant', function (Request $request): array {
            $limits = [
                Limit::perMinute(10)->by(self::person($request)),
                Limit::perMinute(30)->by('ip:'.$request->ip()),
            ];

            if (config('demo.hosted')) {
                $limits[] = Limit::perDay(300)->by(self::person($request).'|day');
            }

            return $limits;
        });

        RateLimiter::for('transcript', fn (Request $request): Limit => Limit::perMinute(30)->by(self::person($request)));

        RateLimiter::for('actions', fn (Request $request): Limit => $request->routeIs('actions._changes')
            ? Limit::perMinute(30)->by(self::person($request).'|changes')
            : Limit::perMinute(60)->by(self::person($request).'|calls'));

        RateLimiter::for('settings', fn (Request $request): Limit => Limit::perMinute(20)->by(self::person($request)));

        RateLimiter::for('tokens', fn (Request $request): Limit => Limit::perMinute(5)->by(self::person($request)));

        RateLimiter::for('oauth-meta', fn (Request $request): Limit => Limit::perMinute(120)->by('ip:'.$request->ip()));

        RateLimiter::for('oauth-register', fn (Request $request): array => IpUtils::checkIp((string) $request->ip(), config('demo.claude_ips'))
            ? [
                Limit::perMinute(120)->by('ip:'.$request->ip()),
                Limit::perDay(500)->by('day:claude'),
            ]
            : [
                Limit::perMinute(10)->by('ip:'.$request->ip()),
                Limit::perDay(30)->by('day:ip:'.$request->ip()),
                Limit::perDay(500)->by('day:others'),
            ]);

        RateLimiter::for('oauth-consent', fn (Request $request): Limit => $request->routeIs('passport.authorizations.*')
            ? Limit::perMinute(20)->by(self::person($request))
            : Limit::none());
    }

    /**
     * Whom a limit counts: the signed-in person, or the address before anyone signs in.
     */
    private static function person(Request $request): string
    {
        $user = $request->user();

        return $user === null ? 'ip:'.$request->ip() : 'user:'.$user->getAuthIdentifier();
    }

    /**
     * Passport, for remote MCP clients that sign in with OAuth (a Claude custom connector, ChatGPT): access tokens
     * that last an hour, with 30-day refresh tokens that rotate, so a leaked token is short-lived while clients refresh
     * on their own; the package's consent data on the app's own Inertia page; and no device flow, whose two pages
     * answer 404 instead of the 500 a missing view gives.
     */
    protected function configureOAuth(): void
    {
        Passport::tokensExpireIn(CarbonInterval::hour());
        Passport::refreshTokensExpireIn(CarbonInterval::days(30));

        Passport::deviceUserCodeView(fn (): never => abort(404));
        Passport::deviceAuthorizationView(fn (): never => abort(404));

        Passport::authorizationView(fn (array $parameters): Response => Inertia::render('oauth/consent', Consent::from($parameters)->toArray())->toResponse(request()));
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
