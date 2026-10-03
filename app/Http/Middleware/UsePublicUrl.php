<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

/**
 * Builds URLs on the public tunnel's https host for the requests that arrive through it, so the OAuth metadata, the
 * 401 challenge and the sign-in redirects name the URL a remote MCP client entered, and never shows those requests a
 * debug page. Only while app.public_url is set, and only for a request on that host (Herd copies X-Forwarded-Host into
 * the host; elsewhere the tunnel's X-Forwarded-Host names it). Every other request, and every request while it is
 * empty, is untouched.
 */
class UsePublicUrl
{
    /**
     * Force the root URL and scheme to the public URL, and debug off, for a request on its host.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $publicUrl = config('app.public_url');
        $publicHost = is_string($publicUrl) ? parse_url($publicUrl, PHP_URL_HOST) : null;

        if (is_string($publicHost) && in_array(strtolower($publicHost), [$request->getHost(), strtolower((string) $request->headers->get('X-Forwarded-Host'))], true)) {
            URL::forceRootUrl(rtrim((string) $publicUrl, '/'));
            URL::forceScheme((string) parse_url((string) $publicUrl, PHP_URL_SCHEME));
            config(['app.debug' => false]);
        }

        return $next($request);
    }
}
