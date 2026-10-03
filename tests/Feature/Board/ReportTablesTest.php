<?php

use AgenticActions\Streaming\AgenticView;
use App\Enums\TaskStatus;
use App\Models\Task;
use App\Models\User;
use Illuminate\Testing\TestResponse;
use Tests\Support\Boards;
use Tests\Support\StreamParts;

/*
 * The three table actions: tasks per column, per assignee and completed per day. Each is one Read action whose
 * declared columns are its output on every surface, and in the copilot the person sees its rows as a table and a
 * chart while the model reads a short copy. Globex's tasks never count on Acme's board.
 */

beforeEach(function () {
    $this->boards = Boards::make();

    // Acme's board beside the fixture's one to-do task: two more to do (one overdue, Marcus's), one doing, two done.
    $acme = ['team_id' => $this->boards->acme->id];
    Task::factory()->create([...$acme, 'status' => TaskStatus::Todo, 'assignee_id' => $this->boards->member->id, 'due_on' => today()->subDay()]);
    Task::factory()->create([...$acme, 'status' => TaskStatus::Todo, 'assignee_id' => $this->boards->member->id, 'due_on' => null]);
    Task::factory()->create([...$acme, 'status' => TaskStatus::Doing, 'assignee_id' => null, 'due_on' => null]);
    Task::factory()->create([...$acme, 'status' => TaskStatus::Done, 'assignee_id' => $this->boards->owner->id, 'completed_at' => now()]);
    Task::factory()->create([...$acme, 'status' => TaskStatus::Done, 'assignee_id' => $this->boards->owner->id, 'completed_at' => now()->subDays(2)]);

    // A done task on Globex's board, which Acme's tables never count.
    Task::factory()->create(['team_id' => $this->boards->globex->id, 'status' => TaskStatus::Done, 'completed_at' => now()]);

    // One call of a table action through its JSON route.
    $this->table = fn (string $action, array $input = [], ?User $user = null): TestResponse => $this->actingAs($user ?? $this->boards->viewer)
        ->postJson(route('actions.'.$action, ['current_team' => 'acme']), $input);
});

afterEach(function () {
    ignore_user_abort(false);
});

it('counts the tasks and the overdue ones per column, in board order, as a bar chart', function () {
    ($this->table)('tasks-by-status')->assertOk()->assertExactJson([
        'columns' => [
            ['key' => 'status', 'label' => 'Column', 'type' => 'text'],
            ['key' => 'tasks', 'label' => 'Tasks', 'type' => 'integer'],
            ['key' => 'overdue', 'label' => 'Overdue', 'type' => 'integer', 'description' => 'Past their due date and not done.'],
        ],
        'rows' => [
            ['status' => 'To do', 'tasks' => 3, 'overdue' => 1],
            ['status' => 'Doing', 'tasks' => 1, 'overdue' => 0],
            ['status' => 'Done', 'tasks' => 2, 'overdue' => 0],
        ],
        'truncated' => false,
        'chart' => ['type' => 'bar', 'x' => 'status', 'y' => ['tasks', 'overdue']],
        'caption' => null,
    ]);
});

it('counts each assignee\'s open and done tasks and the share done, busiest first, the unassigned as one row', function () {
    $response = ($this->table)('tasks-by-assignee')->assertOk();
    $rows = $response->json('rows');

    expect(array_map(fn (array $row): array => [$row['assignee'], $row['open'], $row['done']], $rows))->toBe([
        ['Marcus Reed', 2, 0],
        ['Olivia Park', 1, 2],
        ['Unassigned', 1, 0],
    ])
        ->and($rows[1]['done_share'])->toEqualWithDelta(2 / 3, 0.0001)
        ->and($response->json('chart'))->toBe(['type' => 'bar', 'x' => 'assignee', 'y' => ['open', 'done']]);
});

it('counts the tasks completed each day, every day listed, quiet days as 0, as a line chart', function () {
    $response = ($this->table)('completed-per-day', ['days' => 7])->assertOk();

    expect(array_column($response->json('rows'), 'completed'))->toBe([0, 0, 0, 0, 1, 0, 1])
        ->and($response->json('rows.0.day'))->toBe(today()->subDays(6)->toDateString())
        ->and($response->json('chart'))->toBe(['type' => 'line', 'x' => 'day', 'y' => ['completed']]);

    expect(($this->table)('completed-per-day')->json('rows'))->toHaveCount(14);
    ($this->table)('completed-per-day', ['days' => 6])->assertUnprocessable()->assertJsonValidationErrors('days');
});

it('refuses a person outside the team, as every board action does', function () {
    ($this->table)('tasks-by-status', user: $this->boards->globexOwner)->assertForbidden();
});

it('shows the table in the copilot after its row, keeps it for a reload, and the model reads only a short copy', function () {
    $user = $this->boards->owner;

    $response = $this->actingAs($user)->json('POST', route('assistant', ['current_team' => 'acme']), [
        'id' => 'assistant',
        'messages' => [['id' => 'm1', 'role' => 'user', 'parts' => [['type' => 'text', 'text' => 'Tasks by status']]]],
        'trigger' => 'submit-message',
        'page' => ['url' => '/acme/board', 'component' => 'board'],
    ], ['Accept' => 'application/json, text/event-stream'])->assertOk();

    $parts = StreamParts::of($response->streamedContent());
    $types = StreamParts::types($parts);
    $view = collect($parts)->first(fn ($part): bool => is_array($part) && $part['type'] === 'data-view');
    $done = collect($parts)->search(fn ($part): bool => is_array($part) && $part['type'] === 'data-action' && ($part['data']['status'] ?? null) === 'done');

    expect($view['data']['table']['rows'])->toBe([
        ['status' => 'To do', 'tasks' => 3, 'overdue' => 1],
        ['status' => 'Doing', 'tasks' => 1, 'overdue' => 0],
        ['status' => 'Done', 'tasks' => 2, 'overdue' => 0],
    ])
        ->and(array_search('data-view', $types, true))->toBe($done + 1)
        ->and($view['data']['ref'])->toBe(AgenticView::query()->sole()->id)
        ->and(collect($parts)->where('type', 'text-delta')->pluck('delta')->implode(''))->toBe('To do holds the most tasks, 3, and one task is overdue.');

    $reloaded = $this->actingAs($user)->getJson(route('assistant.transcript', ['current_team' => 'acme']))->assertOk()->json('messages');

    expect($reloaded[1]['parts'][0]['type'])->toBe('data-view')
        ->and($reloaded[1]['parts'][0]['data']['table'])->toBe($view['data']['table']);

    $this->actingAs($user)->postJson("/acme/actions/_views/{$view['data']['ref']}")->assertOk()->assertJsonPath('table.rows.0.tasks', 3);
});

it('says the day of the one task completed, without "most on"', function () {
    $this->boards->acme->tasks()->whereDate('completed_at', '<', today())->update(['completed_at' => null]);

    $response = $this->actingAs($this->boards->owner)->json('POST', route('assistant', ['current_team' => 'acme']), [
        'id' => 'assistant',
        'messages' => [['id' => 'm1', 'role' => 'user', 'parts' => [['type' => 'text', 'text' => 'Tasks completed per day']]]],
        'trigger' => 'submit-message',
    ], ['Accept' => 'application/json, text/event-stream'])->assertOk();

    $words = collect(StreamParts::of($response->streamedContent()))->where('type', 'text-delta')->pluck('delta')->implode('');

    expect($words)->toBe('One task was completed, on '.now()->format('j M').'.');
});
