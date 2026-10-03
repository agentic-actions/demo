<?php

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustHosts;

/**
 * On the hosted demo, the app answers only to the host in APP_URL. A request that names another host in its Host
 * header gets 400, so no link, redirect or OAuth metadata is ever built on a host someone else chose. A local copy
 * trusts every host, since Herd, php artisan serve and a tunnel each use their own.
 *
 * Unlike the framework's own middleware, it sets the trusted hosts on every request, in tests too: an empty list when
 * the demo is not hosted. Symfony keeps the list in a static, which would otherwise outlive the request that set it.
 */
class TrustHostedDemoHost extends TrustHosts
{
    /**
     * APP_URL's host, without its subdomains, while the demo is hosted; none otherwise, which trusts every host.
     *
     * @return array<int, string>
     */
    public function hosts(): array
    {
        $host = parse_url((string) config('app.url'), PHP_URL_HOST);

        if (! config('demo.hosted') || ! is_string($host) || $host === '') {
            return [];
        }

        return ['^'.preg_quote(strtolower($host)).'$'];
    }

    /**
     * Always set the list, so a request never inherits one an earlier request set.
     */
    protected function shouldSpecifyTrustedHosts(): bool
    {
        return true;
    }
}
