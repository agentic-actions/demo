<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bounds on what an OAuth client may register (POST oauth/register): at most five redirect URIs, none longer than 512
 * characters, and a name of at most 100. laravel/mcp's controller checks each URI's shape and domain but not how many
 * there are or how long, and anyone may register, so without these one request could store any amount of text.
 *
 * A request past a bound gets the controller's own error shape (RFC 7591), with status 400.
 */
class LimitClientRegistration
{
    /**
     * The most redirect URIs one client may register.
     */
    public const MAX_REDIRECT_URIS = 5;

    /**
     * The longest redirect URI, in characters.
     */
    public const MAX_URI_LENGTH = 512;

    /**
     * The longest client name, in characters.
     */
    public const MAX_NAME_LENGTH = 100;

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $redirectUris = $request->input('redirect_uris');

        if (is_array($redirectUris)) {
            if (count($redirectUris) > self::MAX_REDIRECT_URIS) {
                return $this->refuse('invalid_redirect_uri', 'A client may register at most '.self::MAX_REDIRECT_URIS.' redirect URIs.');
            }

            foreach ($redirectUris as $redirectUri) {
                if (is_string($redirectUri) && mb_strlen($redirectUri) > self::MAX_URI_LENGTH) {
                    return $this->refuse('invalid_redirect_uri', 'A redirect URI may be at most '.self::MAX_URI_LENGTH.' characters.');
                }
            }
        }

        foreach (['client_name', 'name'] as $field) {
            $name = $request->input($field);

            if (is_string($name) && mb_strlen($name) > self::MAX_NAME_LENGTH) {
                return $this->refuse('invalid_client_metadata', 'A client name may be at most '.self::MAX_NAME_LENGTH.' characters.');
            }
        }

        return $next($request);
    }

    /**
     * A registration error as the controller words one.
     */
    private function refuse(string $error, string $description): JsonResponse
    {
        return response()->json(['error' => $error, 'error_description' => $description], 400);
    }
}
