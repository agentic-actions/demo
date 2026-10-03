<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Hosted Demo
    |--------------------------------------------------------------------------
    |
    | On the public host, every visitor gets a sandbox of their own: a copy
    | of Acme and Globex with its own people, gone after "ttl_hours". The
    | kit's account features that mail or lock an account are off there.
    | Off by default, so a local copy behaves as the starter kit does.
    |
    */

    'hosted' => (bool) env('DEMO_HOSTED', false),

    'ttl_hours' => 24,

    /*
    |--------------------------------------------------------------------------
    | Limits
    |--------------------------------------------------------------------------
    |
    | "max_live" is how many sandboxes may be alive at once; past it, "Try
    | the demo" answers 503. "starts_per_hour" bounds new sandboxes from
    | one address and in all. The caps bound what one sandbox can hold:
    | "teams_per_sandbox" counts the teams a visitor adds, not the
    | sample ones. They apply only while the demo is hosted.
    |
    */

    'max_live' => 500,

    'starts_per_hour' => [
        'address' => 5,
        'all' => 120,
    ],

    'caps' => [
        'tasks_per_team' => 200,
        'projects_per_team' => 20,
        'teams_per_sandbox' => 5,
        'tokens_per_user_team' => 5,
    ],

    /*
    |--------------------------------------------------------------------------
    | Claude's Addresses
    |--------------------------------------------------------------------------
    |
    | Anthropic's outbound range. Every Claude.ai custom connector registers
    | its OAuth client from it, so registration allows these addresses more
    | than any other (AppServiceProvider::configureRateLimits).
    |
    */

    'claude_ips' => ['160.79.104.0/21'],

];
