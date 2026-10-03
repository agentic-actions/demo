<?php

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Testing\TestResponse;
use Tests\Support\Boards;
use Tests\Support\StreamParts;

/*
 * The two datasets: the model asks its own question of the team's tasks, by when they were added, or of the done ones,
 * by when they were completed, within the names each dataset declares, and the package writes the query inside the
 * team. Globex's tasks never count on Acme's board.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-30 12:00:00'));
    $this->boards = Boards::make();

    // Acme beside the fixture's high-priority website task of Olivia's: three more on the website, one on the mobile
    // app, one with no project. Two are done, and a task in Doing still carries a completion time.
    $acme = ['team_id' => $this->boards->acme->id];
    $website = [...$acme, 'project_id' => $this->boards->website->id];
    $mobile = Project::factory()->create([...$acme, 'name' => 'Mobile app']);

    Task::factory()->create([...$website, 'status' => TaskStatus::Done, 'priority' => TaskPriority::High, 'assignee_id' => $this->boards->member->id, 'created_at' => '2026-09-01 09:00:00', 'completed_at' => '2026-09-03 16:00:00']);
    Task::factory()->create([...$website, 'status' => TaskStatus::Doing, 'priority' => TaskPriority::Normal, 'assignee_id' => $this->boards->member->id, 'created_at' => '2026-09-10 09:00:00', 'completed_at' => '2026-09-16 16:00:00']);
    Task::factory()->create([...$website, 'status' => TaskStatus::Todo, 'priority' => TaskPriority::High, 'assignee_id' => $this->boards->owner->id, 'created_at' => '2026-09-15 09:00:00']);
    Task::factory()->create([...$acme, 'project_id' => $mobile->id, 'status' => TaskStatus::Todo, 'priority' => TaskPriority::High, 'assignee_id' => null, 'created_at' => '2026-09-25 09:00:00']);
    Task::factory()->create([...$acme, 'project_id' => null, 'status' => TaskStatus::Done, 'priority' => TaskPriority::Low, 'assignee_id' => $this->boards->owner->id, 'created_at' => '2026-09-20 09:00:00', 'completed_at' => '2026-09-22 16:00:00']);

    // A done high-priority task on Globex's board, which Acme's answers never count.
    Task::factory()->create(['team_id' => $this->boards->globex->id, 'status' => TaskStatus::Done, 'priority' => TaskPriority::High, 'created_at' => '2026-09-28 09:00:00', 'completed_at' => '2026-09-29 16:00:00']);

    // One question to a dataset through its JSON route.
    $this->ask = fn (string $dataset, array $input, ?User $user = null): TestResponse => $this->actingAs($user ?? $this->boards->viewer)
        ->postJson(route('actions.'.$dataset, ['current_team' => 'acme']), $input);
});

afterEach(function () {
    ignore_user_abort(false);
});

it('counts the team\'s tasks per project with the share done, the biggest first and the tasks with no project last', function () {
    $response = ($this->ask)('task-stats', ['measures' => ['tasks', 'done_share'], 'by' => ['project']])->assertOk();
    $rows = $response->json('rows');

    expect(array_map(fn (array $row): array => [$row['project'], $row['tasks']], $rows))->toBe([
        ['Website relaunch', 4],
        ['Mobile app', 1],
        [null, 1],
    ])
        ->and(array_column($rows, 'done_share'))->toEqualWithDelta([0.25, 0, 1], 0.0001)
        ->and($response->json('chart'))->toBe(['type' => 'bar', 'x' => 'project', 'y' => ['tasks']])
        ->and($response->json('caption'))->toBe('Tasks, Done share by Project · 1 Oct 2025 – 30 Sep 2026 · the 50 highest by Tasks');
});

it('answers a question no report was written for: the high-priority tasks per person', function () {
    $response = ($this->ask)('task-stats', [
        'measures' => ['tasks'],
        'by' => ['assignee'],
        'filters' => [['dimension' => 'priority', 'values' => ['high']]],
    ])->assertOk();

    expect($response->json('rows'))->toBe([
        ['assignee' => 'Olivia Park', 'tasks' => 2],
        ['assignee' => 'Marcus Reed', 'tasks' => 1],
        ['assignee' => null, 'tasks' => 1],
    ])
        ->and($response->json('caption'))->toBe('Tasks by Assignee · 1 Oct 2025 – 30 Sep 2026 · Priority is High · the 50 highest by Tasks');
});

it('counts the tasks completed each week, every week of the range listed, the done tasks only', function () {
    $response = ($this->ask)('completion-stats', ['measures' => ['tasks'], 'grain' => 'week', 'since' => '-8w'])->assertOk();
    $rows = $response->json('rows');

    expect(array_column($rows, 'completed'))->toBe(['2026-08-10', '2026-08-17', '2026-08-24', '2026-08-31', '2026-09-07', '2026-09-14', '2026-09-21', '2026-09-28'])
        ->and(array_column($rows, 'tasks'))->toBe([0, 0, 0, 1, 0, 0, 1, 0])
        ->and($response->json('chart'))->toBe(['type' => 'line', 'x' => 'completed', 'y' => ['tasks']])
        ->and($response->json('caption'))->toBe('Completed tasks by week · 10 Aug 2026 – 30 Sep 2026');
});

it('refuses a name the dataset does not declare, and a value its enum does not have, before any query', function () {
    ($this->ask)('task-stats', ['measures' => ['tasks'], 'by' => ['title']])->assertUnprocessable()->assertJsonValidationErrors('by.0');
    ($this->ask)('task-stats', ['measures' => ['tasks'], 'filters' => [['dimension' => 'priority', 'values' => ['urgent']]]])->assertUnprocessable();
});

it('refuses a person outside the team, as every board action does', function () {
    ($this->ask)('task-stats', ['measures' => ['tasks']], $this->boards->globexOwner)->assertForbidden();
});

it('asks the datasets in demo mode and shows each answer as a table with its caption', function (string $prompt, string $caption, string $said) {
    $response = $this->actingAs($this->boards->owner)->json('POST', route('assistant', ['current_team' => 'acme']), [
        'id' => 'assistant',
        'messages' => [['id' => 'm1', 'role' => 'user', 'parts' => [['type' => 'text', 'text' => $prompt]]]],
        'trigger' => 'submit-message',
        'page' => ['url' => '/acme/board', 'component' => 'board'],
    ], ['Accept' => 'application/json, text/event-stream'])->assertOk();

    $parts = StreamParts::of($response->streamedContent());
    $view = collect($parts)->first(fn ($part): bool => is_array($part) && $part['type'] === 'data-view');

    expect($view['data']['table']['caption'])->toBe($caption)
        ->and(collect($parts)->where('type', 'text-delta')->pluck('delta')->implode(''))->toBe($said);
})->with([
    'per project' => ['Tasks per project', 'Tasks, Done share by Project · 1 Oct 2025 – 30 Sep 2026 · the 50 highest by Tasks', 'Website relaunch has the most tasks, 4, and 25% of them are done.'],
    'high priority' => ['High-priority tasks per person', 'Tasks by Assignee · 1 Oct 2025 – 30 Sep 2026 · Priority is High · the 50 highest by Tasks', 'Olivia Park holds the most high-priority tasks, 2.'],
    'per week' => ['Tasks completed per week', 'Completed tasks by week · 10 Aug 2026 – 30 Sep 2026', '2 tasks were completed in these 8 weeks, most in the week of 31 Aug.'],
]);
