<?php

use AgenticActions\Streaming\AgenticView;
use App\Models\TeamInvitation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

Schedule::call(function () {
    TeamInvitation::query()
        ->whereNotNull('expires_at')
        ->where('expires_at', '<', now())
        ->delete();
})->daily()->description('Delete expired team invitations');

// The tables the copilot showed, kept for reloads, go when their conversation goes (a day's wait at least).
Schedule::command('model:prune', ['--model' => [AgenticView::class]])->daily()->description('Prune the kept tables of deleted conversations');

// The hosted demo: expired sandboxes, then OAuth clients registered and never connected, then the tokens that ended.
// A run takes seconds, so a lock left by a killed run holds the next runs back ten minutes, not the default day.
Schedule::command('demo:prune-sandboxes')->everyFifteenMinutes()->withoutOverlapping(10)->description('Delete expired demo sandboxes');
Schedule::command('demo:prune-clients')->daily()->description('Delete OAuth clients nobody connected');
Schedule::command('passport:purge')->daily()->description('Purge revoked and expired OAuth tokens and codes');
Schedule::command('sanctum:prune-expired', ['--hours' => 24])->daily()->description('Prune expired MCP tokens');

// The database cache store deletes an expired row only when its key is read again, and most keys (a limiter's count
// for one address, a sandbox's change feed) never are. Redis expires its own keys.
Schedule::call(function () {
    DB::connection(config('cache.stores.database.connection'))
        ->table((string) config('cache.stores.database.table', 'cache'))
        ->where('expiration', '<=', now()->getTimestamp())
        ->delete();
})->daily()->when(fn (): bool => config('cache.default') === 'database')->description('Delete expired cache rows');
