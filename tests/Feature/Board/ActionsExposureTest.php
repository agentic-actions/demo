<?php

/*
 * The committed actions.exposure.json and resources/js/agentic/actions.ts must match what the classes declare. A new
 * route, a widened toolset or a changed schema fails here until someone reviews it and regenerates.
 */

it('keeps the exposure snapshot current and every check passing', function () {
    $this->artisan('actions:check')->assertSuccessful();
});

it('keeps the generated TypeScript current', function () {
    $this->artisan('actions:typescript', ['--check' => true])->assertSuccessful();
});
