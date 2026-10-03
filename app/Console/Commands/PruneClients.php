<?php

namespace App\Console\Commands;

use AgenticActions\OAuth\McpConnection;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Client;
use Laravel\Passport\Passport;

/**
 * Delete the OAuth clients that dynamic registration left unused. Every new connection from Claude registers a client,
 * and anyone may register one, so a client older than a day that no one connected and that holds no live access token
 * goes, with its expired or revoked tokens and codes. Only public clients with no owner are considered: that is what
 * registration makes, so a client made with passport:client stays.
 */
#[Signature('demo:prune-clients {--hours=24 : Keep clients registered within this many hours}')]
#[Description('Delete OAuth clients registered over a day ago that nobody connected')]
class PruneClients extends Command
{
    /**
     * Delete the unused clients.
     */
    public function handle(): int
    {
        $connected = McpConnection::query()->select('client_id');
        $live = Passport::token()->newQuery()->select('client_id')->where('revoked', false)->where('expires_at', '>', now());

        $count = 0;

        Passport::client()->newQuery()
            ->whereNull('owner_id')
            ->whereNull('secret')
            ->where('created_at', '<', now()->subHours((int) $this->option('hours')))
            ->whereNotIn('id', $connected)
            ->whereNotIn('id', $live)
            ->lazyById()
            ->each(function (Client $client) use (&$count): void {
                DB::transaction(function () use ($client): void {
                    $tokens = Passport::token()->newQuery()->where('client_id', $client->id);

                    Passport::refreshToken()->newQuery()->whereIn('access_token_id', (clone $tokens)->select('id'))->delete();
                    $tokens->delete();
                    Passport::authCode()->newQuery()->where('client_id', $client->id)->delete();
                    Passport::deviceCode()->newQuery()->where('client_id', $client->id)->delete();
                    $client->delete();
                });

                $count++;
            });

        $this->components->info("Deleted {$count} unused OAuth ".str('client')->plural($count).'.');

        return self::SUCCESS;
    }
}
