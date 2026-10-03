<?php

use App\Http\Middleware\DisabledOnHostedDemo;
use App\Http\Middleware\EndExpiredSandbox;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SetTeamUrlDefaults;
use App\Http\Middleware\TrustHostedDemoHost;
use App\Http\Middleware\UsePublicUrl;
use App\Http\Responses\TooManyTries;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use League\OAuth2\Server\Exception\OAuthServerException;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        // URLs on the public tunnel's host for requests that arrive through it, only while APP_PUBLIC_URL is set.
        $middleware->prepend(UsePublicUrl::class);

        // On the hosted demo, only APP_URL's host; prepended last, so it runs before anything reads the host.
        $middleware->prepend(TrustHostedDemoHost::class);

        $middleware->web(append: [
            EndExpiredSandbox::class,
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
            SetTeamUrlDefaults::class,
            DisabledOnHostedDemo::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // A bearer token no MCP guard can read (an expired or revoked one) answers 401; Passport's guard would also log
        // it as an error. League's class, not Passport's class of the same name.
        $exceptions->dontReport(OAuthServerException::class);

        // A limiter's 429 says what to do in a plain line with how long to wait, read from Retry-After, as JSON for the
        // assistant, MCP and OAuth clients, and as text for a page (the board and the toasts show it as it is).
        $exceptions->render(function (ThrottleRequestsException $exception, Request $request): Response {
            $message = TooManyTries::message($exception->getHeaders()['Retry-After'] ?? null);

            return $request->expectsJson()
                ? response()->json(['message' => $message], 429, $exception->getHeaders())
                : response($message, 429, [...$exception->getHeaders(), 'Content-Type' => 'text/plain; charset=UTF-8']);
        });

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
