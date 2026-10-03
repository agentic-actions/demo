<?php

use App\Http\Middleware\EndExpiredSandbox;
use App\Models\Team;
use App\Tenancy\TeamMembership;
use App\Tenancy\TeamScope;

return [

    /*
    |--------------------------------------------------------------------------
    | Discovery
    |--------------------------------------------------------------------------
    |
    | Where the scanner looks for Action classes. Paths are relative to the
    | application's base path unless they are absolute, and may use glob
    | patterns such as "Modules/*". A missing path is skipped. List
    | classes outside these paths under "classes".
    |
    */

    'discovery' => [
        'paths' => ['app'],
        'classes' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Surfaces
    |--------------------------------------------------------------------------
    |
    | App-wide switches, read when routes and tools are built and again on
    | every call, so they still apply when routes are cached. They are on by
    | default; an environment variable only turns one off.
    |
    */

    'surfaces' => [
        'web' => (bool) env('AGENTIC_ACTIONS_WEB', true),
        'agents' => (bool) env('AGENTIC_ACTIONS_AGENTS', true),
        'mcp' => (bool) env('AGENTIC_ACTIONS_MCP', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Generated routes
    |--------------------------------------------------------------------------
    |
    | Actions::routes() registers POST {group prefix}/{path}/{action} named
    | {group name prefix}{name}{action} inside whatever group calls it.
    |
    */

    'routes' => [
        'path' => 'actions',
        'name' => 'actions.',
    ],

    /*
    |--------------------------------------------------------------------------
    | Tenant
    |--------------------------------------------------------------------------
    |
    | Leave "model" null when the app has no tenants. Once it is set,
    | actions are tenant-scoped unless they set $tenantScoped = false, the
    | "parameter" route segment carries the tenant (resolved by route key),
    | and "membership" must name a ChecksMembership class. "scope" names a
    | ScopesToTenant class used by ActionContext::find().
    |
    */

    'tenant' => [
        'model' => Team::class,
        'parameter' => 'current_team',
        'membership' => TeamMembership::class,
        'scope' => TeamScope::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Tenancy bridge
    |--------------------------------------------------------------------------
    |
    | A Tenancy class run around every pipeline step, for example
    | AgenticActions\Tenancy\SpatieTeams. Null runs nothing.
    |
    */

    'tenancy' => null,

    /*
    |--------------------------------------------------------------------------
    | Token abilities
    |--------------------------------------------------------------------------
    |
    | The ability a token needs for each effect, and the prefix that binds a
    | token to one tenant: "tenant:{primary key}". On HTTP a "*" ability
    | counts, as it does for Sanctum's tokenCan().
    |
    */

    'abilities' => [
        'read' => 'actions:read',
        'write' => 'actions:write',
        'destructive' => 'actions:destructive',
        'external' => 'actions:external',
        'tenant' => 'tenant:',
    ],

    /*
    |--------------------------------------------------------------------------
    | Agents
    |--------------------------------------------------------------------------
    |
    | Keys an agent may never be offered, matched as case-insensitive globs at
    | any depth of the advertised input. Route parameters, the tenant
    | parameter and the tenant model's foreign key are always forbidden too.
    | Id-shaped keys (id, *_id, uuid) are allowed, under the checks in
    | actions:check. Add them here to forbid ids outright.
    | "max_message_length" is the longest chat message ChatRequest reads,
    | in characters.
    |
    */

    'agents' => [
        'forbidden_keys' => ['*password*', '*secret*', '*token*', 'api_key'],
        'forbidden_output_keys' => [],
        'max_tools_per_toolset' => 20,
        'max_message_length' => 4000,
    ],

    /*
    |--------------------------------------------------------------------------
    | MCP
    |--------------------------------------------------------------------------
    |
    | The package mounts one laravel/mcp server for the actions that allow
    | MCP, at "path", and at "tenant_path" for tenant-scoped actions. Null
    | mounts nothing there. A path another route already holds is left
    | alone, and actions:check fails. "middleware" must authenticate with a
    | token guard: inside MCP a session grants nothing. "tenant_pattern"
    | null derives one: digits for an incrementing route key, none otherwise.
    |
    | Every board action is tenant-scoped, so the demo serves them at one
    | URL per team, by slug: https://agentic-actions-demo.test/mcp/t/acme. The
    | bearer credential is a Sanctum token from the team's "AI clients"
    | settings page, or a Passport token a remote client (a Claude custom
    | connector) got by signing in with OAuth. Naming the Passport guard
    | "api" here is what turns the package's OAuth on; Sanctum stays first,
    | or every Sanctum token would answer 401. EndExpiredSandbox stops a
    | hosted sandbox's tokens the moment the sandbox expires.
    |
    */

    'mcp' => [
        'path' => null,
        'tenant_path' => 'mcp/t/{current_team}',
        'tenant_pattern' => null,
        'middleware' => ['auth:sanctum,api', EndExpiredSandbox::class, 'throttle:agentic-actions-mcp'],
        'per_minute' => 60,
    ],

    /*
    |--------------------------------------------------------------------------
    | Change feed
    |--------------------------------------------------------------------------
    |
    | Writes made elsewhere (over MCP, by the queue, in another tab) reach an
    | open page through a polled feed. It keeps touched keys in the default
    | cache store for "window" seconds; use a store every server shares
    | (database or redis), not array, file or session.
    |
    */

    'feed' => [
        'enabled' => true,
        'window' => 600,
    ],

    /*
    |--------------------------------------------------------------------------
    | Reads
    |--------------------------------------------------------------------------
    |
    | While a Read action's own code runs (authorize() through handle() and
    | its reply), statements that write are refused before they execute.
    | Cache, session and queue tables are derived from the app's own config;
    | list any other table a Read may write here. The guard also stops a
    | Read's own code from queueing an action that is not a Read
    | (Action::dispatch()); off, both are off.
    |
    */

    'reads' => [
        'guard' => true,
        'writable_tables' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Exposure snapshot
    |--------------------------------------------------------------------------
    |
    | The committed, reviewable list of what every action exposes. Only
    | "php artisan actions:check --update" writes it.
    |
    */

    'snapshot' => 'actions.exposure.json',

    /*
    |--------------------------------------------------------------------------
    | TypeScript
    |--------------------------------------------------------------------------
    |
    | Where "php artisan actions:typescript" writes its file. Relative to the
    | base path unless absolute.
    |
    */

    'typescript' => [
        'path' => 'resources/js/agentic/actions.ts',
    ],

];
