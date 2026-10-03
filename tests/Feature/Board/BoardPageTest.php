<?php

use App\Models\Task;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Boards;

/*
 * The board page renders the ListTasks and SummarizeBoard actions, run in-process for the signed-in member.
 */

beforeEach(function () {
    $this->boards = Boards::make();
});

it('shows a team its own tasks, counts and permissions', function () {
    $this->actingAs($this->boards->owner)
        ->get(route('board', ['current_team' => 'acme']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('board')
            ->has('tasks', 1)
            ->where('tasks.0.title', 'Write the launch announcement')
            ->where('tasks.0.assignee.email', 'owner@example.com')
            ->where('summary.todo', 1)
            ->where('summary.total', 1)
            ->has('projects', 1)
            ->has('members', 4)
            ->where('role', 'Owner')
            ->where('can', ['createTask' => true, 'updateTask' => true, 'deleteTask' => true, 'createProject' => true]));
});

it('shows a viewer the board with every permission off', function () {
    $this->actingAs($this->boards->viewer)
        ->get(route('board', ['current_team' => 'acme']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('tasks', 1)
            ->where('role', 'Viewer')
            ->where('can', ['createTask' => false, 'updateTask' => false, 'deleteTask' => false, 'createProject' => false]));
});

it('passes the query string to ListTasks as filters', function () {
    Task::factory()->create(['team_id' => $this->boards->acme->id, 'title' => 'Marcus\'s task', 'assignee_id' => $this->boards->member->id]);

    $this->actingAs($this->boards->member)
        ->get(route('board', ['current_team' => 'acme', 'assignee' => 'me']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('tasks', 1)
            ->where('tasks.0.title', 'Marcus\'s task')
            ->where('filters.assignee', 'me'));
});

it('falls back to every task when a hand-typed filter names nobody', function () {
    $this->actingAs($this->boards->owner)
        ->get(route('board', ['current_team' => 'acme', 'assignee' => 'nobody@example.com']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('tasks', 1));
});

it('keeps a user out of a team they do not belong to', function () {
    $this->actingAs($this->boards->globexOwner)
        ->get(route('board', ['current_team' => 'acme']))
        ->assertForbidden();
});

it('sends guests to the login page', function () {
    $this->get(route('board', ['current_team' => 'acme']))
        ->assertRedirect(route('login'));
});
