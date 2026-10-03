<?php

use App\Http\Middleware\LimitClientRegistration;
use Illuminate\Support\Facades\Route;
use Laravel\Mcp\Facades\Mcp;
use Laravel\Mcp\Server\Http\Controllers\OAuthRegisterController;

// OAuth discovery and client registration for remote MCP clients (a Claude custom connector, ChatGPT): the metadata
// under .well-known and POST oauth/register. Passport is the authorization server; the package's MCP URLs are in
// config/agentic-actions.php.
Route::middleware('throttle:oauth-meta')->group(fn () => Mcp::oauthRoutes());

// Registration again, in place of the one oauthRoutes() just added (a route with the same method and URI replaces
// it): the same controller behind a limiter of its own and bounds on what a client may register.
Route::post('oauth/register', OAuthRegisterController::class)->middleware(['throttle:oauth-register', LimitClientRegistration::class]);
