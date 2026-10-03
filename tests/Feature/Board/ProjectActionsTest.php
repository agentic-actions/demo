<?php

use App\Models\Project;
use Tests\Support\Boards;

/*
 * create-project, as the board's "New project" dialog calls it: an Inertia <Form> visit. JSON callers are covered too.
 */

beforeEach(function () {
    $this->boards = Boards::make();
});

it('lets owners and admins create a project', function (string $role) {
    $this->actingAs($this->boards->{$role})
        ->postJson(Boards::url('create-project', $this->boards->acme), ['name' => 'Mobile app', 'description' => 'The first release.'])
        ->assertOk()
        ->assertExactJsonStructure(['id', 'name'])
        ->assertJsonPath('name', 'Mobile app');

    expect($this->boards->acme->projects()->pluck('name')->all())->toContain('Mobile app');
})->with(['owner', 'admin']);

it('refuses members and viewers', function (string $role) {
    $this->actingAs($this->boards->{$role})
        ->postJson(Boards::url('create-project', $this->boards->acme), ['name' => 'Mobile app'])
        ->assertForbidden();

    $this->assertDatabaseMissing('projects', ['name' => 'Mobile app']);
})->with(['member', 'viewer']);

it('refuses a name the team already uses, ignoring case, on the name field', function () {
    $this->actingAs($this->boards->owner)
        ->postJson(Boards::url('create-project', $this->boards->acme), ['name' => 'WEBSITE relaunch'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name' => 'This team already has a project by that name.']);
});

it('lets another team use the same name', function () {
    $this->actingAs($this->boards->globexOwner)
        ->postJson(Boards::url('create-project', $this->boards->globex), ['name' => 'Website relaunch'])
        ->assertOk();

    expect(Project::query()->where('name', 'Website relaunch')->count())->toBe(2);
});

it('answers an Inertia visit with a 303 back and the result flashed', function () {
    $this->actingAs($this->boards->owner)
        ->from('/acme/board')
        ->withHeaders(['X-Inertia' => 'true'])
        ->post(Boards::url('create-project', $this->boards->acme), ['name' => 'Mobile app'])
        ->assertStatus(303)
        ->assertRedirect('/acme/board');

    expect($this->boards->acme->projects()->where('name', 'Mobile app')->exists())->toBeTrue();
});

it('sends an Inertia visit back with the refusal in the error bag', function () {
    $this->actingAs($this->boards->owner)
        ->from('/acme/board')
        ->withHeaders(['X-Inertia' => 'true'])
        ->post(Boards::url('create-project', $this->boards->acme), ['name' => 'Website relaunch'])
        ->assertRedirect('/acme/board')
        ->assertSessionHasErrors(['name' => 'This team already has a project by that name.']);
});
