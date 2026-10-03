<?php

use App\Enums\TaskStatus;
use App\Models\Task;
use Tests\Support\Boards;

/*
 * php artisan actions:run {action} key=value --as={user id} --tenant={team slug}: the same pipeline as the web,
 * with the operator acting as a user inside a team.
 */

beforeEach(function () {
    $this->boards = Boards::make();
});

it('creates a task as a member of the team', function () {
    $this->artisan('actions:run', [
        'name' => 'create-task',
        'input' => ['title=Filed from the CLI', 'assignee=Marcus Reed', 'priority=high'],
        '--as' => (string) $this->boards->owner->id,
        '--tenant' => 'acme',
    ])->assertExitCode(0);

    $task = Task::query()->where('title', 'Filed from the CLI')->sole();

    expect($task->team_id)->toBe($this->boards->acme->id)
        ->and($task->assignee_id)->toBe($this->boards->member->id);
});

it('denies a viewer with exit code 3', function () {
    $this->artisan('actions:run', [
        'name' => 'create-task',
        'input' => ['title=Not allowed'],
        '--as' => (string) $this->boards->viewer->id,
        '--tenant' => 'acme',
    ])->assertExitCode(3);

    $this->assertDatabaseMissing('tasks', ['title' => 'Not allowed']);
});

it('reads a team the user is not in as not found', function () {
    $this->artisan('actions:run', [
        'name' => 'summarize-board',
        '--as' => (string) $this->boards->globexOwner->id,
        '--tenant' => 'acme',
    ])->assertExitCode(1);
});

it('reads another team\'s task number as not found', function () {
    $this->artisan('actions:run', [
        'name' => 'update-task',
        'input' => ['task='.$this->boards->acmeTask->id, 'status=done'],
        '--as' => (string) $this->boards->globexOwner->id,
        '--tenant' => 'globex',
    ])->assertExitCode(1);

    expect($this->boards->acmeTask->fresh()->status)->toBe(TaskStatus::Todo);
});

it('fails invalid input with exit code 2', function () {
    $this->artisan('actions:run', [
        'name' => 'create-task',
        'input' => ['priority=urgent'],
        '--as' => (string) $this->boards->owner->id,
        '--tenant' => 'acme',
    ])->assertExitCode(2);
});

it('completes a task by number, and refuses one that is already done', function () {
    $run = fn () => $this->artisan('actions:run', [
        'name' => 'complete-task',
        'input' => ['task='.$this->boards->acmeTask->id],
        '--as' => (string) $this->boards->member->id,
        '--tenant' => 'acme',
    ]);

    $run()->assertExitCode(0);

    expect($this->boards->acmeTask->fresh()->status)->toBe(TaskStatus::Done);

    $run()->assertExitCode(1);
});

it('reaches delete-task, which agents never receive', function () {
    $this->artisan('actions:run', [
        'name' => 'delete-task',
        'input' => ['task='.$this->boards->acmeTask->id],
        '--as' => (string) $this->boards->owner->id,
        '--tenant' => 'acme',
    ])->assertExitCode(0);

    $this->assertModelMissing($this->boards->acmeTask);
});

it('still asks authorize() about delete-task', function () {
    $this->artisan('actions:run', [
        'name' => 'delete-task',
        'input' => ['task='.$this->boards->acmeTask->id],
        '--as' => (string) $this->boards->member->id,
        '--tenant' => 'acme',
    ])->assertExitCode(3);

    $this->assertModelExists($this->boards->acmeTask);
});

it('refuses an unknown team', function () {
    $this->artisan('actions:run', [
        'name' => 'summarize-board',
        '--as' => (string) $this->boards->owner->id,
        '--tenant' => 'initech',
    ])->assertExitCode(1);
});
