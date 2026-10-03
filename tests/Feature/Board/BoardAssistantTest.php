<?php

use AgenticActions\Ai\ActionTool;
use AgenticActions\Facades\Actions;
use App\Ai\Agents\BoardAssistant;
use App\Enums\TaskStatus;
use App\Models\Task;
use App\Models\Team;
use App\Models\User;
use Laravel\Ai\Responses\Data\ToolCall;
use Tests\Support\Boards;

/*
 * The assistant's tools, and real tool calls through laravel/ai's fake gateway: the model's call goes through the
 * package's agent door, the whole pipeline and the database. No provider key is needed.
 */

beforeEach(function () {
    $this->boards = Boards::make();
});

/**
 * The assistant as the chat endpoint builds it: continued in this person's conversation in this team.
 */
function assistantFor(User $user, Team $team): BoardAssistant
{
    return (new BoardAssistant($user, $team))->continue(Actions::conversation(BoardAssistant::class, $user, $team)->open(), as: $user);
}

/**
 * The names of the action tools an assistant receives this turn.
 *
 * @return list<string>
 */
function toolNames(BoardAssistant $assistant): array
{
    return collect($assistant->tools())
        ->filter(fn (mixed $tool): bool => $tool instanceof ActionTool)
        ->map(fn (ActionTool $tool): string => $tool->name())
        ->sort()
        ->values()
        ->all();
}

it('keeps the default toolset reviewed: every action, delete-task behind a confirmation', function () {
    Actions::assertToolset('default', [
        'complete-task',
        'completed-per-day',
        'completion-stats',
        'create-project',
        'create-task',
        'delete-task',
        'list-tasks',
        'search-tasks',
        'summarize-board',
        'task-stats',
        'tasks-by-assignee',
        'tasks-by-status',
        'update-task',
    ]);

    Actions::assertAgentTools(assistantFor($this->boards->owner, $this->boards->acme));
});

it('offers each person only the tools their role may run', function () {
    $acme = $this->boards->acme;

    expect(toolNames(assistantFor($this->boards->owner, $acme)))
        ->toBe(['complete-task', 'completed-per-day', 'completion-stats', 'create-project', 'create-task', 'delete-task', 'list-tasks', 'search-tasks', 'summarize-board', 'task-stats', 'tasks-by-assignee', 'tasks-by-status', 'update-task'])
        ->and(toolNames(assistantFor($this->boards->admin, $acme)))
        ->toBe(['complete-task', 'completed-per-day', 'completion-stats', 'create-project', 'create-task', 'delete-task', 'list-tasks', 'search-tasks', 'summarize-board', 'task-stats', 'tasks-by-assignee', 'tasks-by-status', 'update-task'])
        ->and(toolNames(assistantFor($this->boards->member, $acme)))
        ->toBe(['complete-task', 'completed-per-day', 'completion-stats', 'create-task', 'list-tasks', 'search-tasks', 'summarize-board', 'task-stats', 'tasks-by-assignee', 'tasks-by-status', 'update-task'])
        ->and(toolNames(assistantFor($this->boards->viewer, $acme)))
        ->toBe(['completed-per-day', 'completion-stats', 'list-tasks', 'search-tasks', 'summarize-board', 'task-stats', 'tasks-by-assignee', 'tasks-by-status'])
        ->and(toolNames(assistantFor($this->boards->globexOwner, $acme)))
        ->toBe([]);

    expect(assistantFor($this->boards->owner, $acme)->tools())->toContainActionTool('create-task');
});

it('offers delete-task only to an assistant whose conversation is stored, so the person can be asked', function () {
    // Without a conversation there is nobody to show a card to, so the package leaves the tool out.
    expect(toolNames(new BoardAssistant($this->boards->owner, $this->boards->acme)))->not->toContain('delete-task')
        ->and(toolNames(assistantFor($this->boards->owner, $this->boards->acme)))->toContain('delete-task');
});

it('tells the model to act on a request that leaves details open, instead of asking for them', function () {
    // Without this line a real model answers the "Add three launch tasks for Marcus" chip by asking for titles.
    $instructions = (string) (new BoardAssistant($this->boards->owner, $this->boards->acme))->instructions();

    expect($instructions)
        ->toContain('Act on a request instead of asking about it.')
        ->toContain('choose short, sensible ones yourself')
        ->toContain('pick the one the person meant and try again');
});

it('names the team\'s members in the instructions, and no one from another team', function () {
    // A model then names Marcus in full, a value create-task's form offers, so he opens selected there.
    $instructions = (string) (new BoardAssistant($this->boards->owner, $this->boards->acme))->instructions();

    expect($instructions)
        ->toContain('The members of Acme are Ada Admin <admin@example.com>, Marcus Reed <member@example.com>, Olivia Park <owner@example.com>, Vera Lind <viewer@example.com>.')
        ->not->toContain('globex@example.com');
});

it('completes a task the model names by its title', function () {
    BoardAssistant::fake([
        new ToolCall('call_1', 'complete-task', ['title' => 'launch announcement']),
        'Marked it done.',
    ]);

    (new BoardAssistant($this->boards->member, $this->boards->acme))->prompt('The launch announcement is finished.');

    expect($this->boards->acmeTask->fresh()->status)->toBe(TaskStatus::Done);
});

it('creates a task with the assignee and project named in words', function () {
    BoardAssistant::fake([
        new ToolCall('call_1', 'create-task', ['title' => 'Book the photographer', 'assignee' => 'Marcus Reed', 'project' => 'Website relaunch', 'priority' => 'high', 'due_on' => today()->addWeek()->toDateString()]),
        'Created.',
    ]);

    (new BoardAssistant($this->boards->owner, $this->boards->acme))->prompt('Ask Marcus to book the photographer for the website, high priority.');

    $task = Task::query()->where('title', 'Book the photographer')->sole();

    expect($task->team_id)->toBe($this->boards->acme->id)
        ->and($task->assignee_id)->toBe($this->boards->member->id)
        ->and($task->project_id)->toBe($this->boards->website->id)
        ->and($task->created_by)->toBe($this->boards->owner->id);
});

it('cannot complete another team\'s task by naming it', function () {
    BoardAssistant::fake([
        new ToolCall('call_1', 'complete-task', ['title' => 'Cancel the Elm Street lease']),
        'I could not find it.',
    ]);

    (new BoardAssistant($this->boards->member, $this->boards->acme))->prompt('Cancel the Elm Street lease is done.');

    expect($this->boards->globexTask->fresh()->status)->toBe(TaskStatus::Todo);
});

it('cannot delete a task without a stored conversation, even when the model asks for the tool by name', function () {
    BoardAssistant::fake([
        new ToolCall('call_1', 'delete-task', ['task' => $this->boards->acmeTask->id]),
        'I cannot delete tasks.',
    ]);

    // #[RepairToolCalls]: laravel/ai answers the call with "does not exist" and the turn carries on, instead of
    // throwing NoSuchToolException, so the model can tell the person. The confirmed path is AssistantApprovalTest.
    $response = (new BoardAssistant($this->boards->owner, $this->boards->acme))->prompt('Delete the launch announcement.');

    expect($response->text)->toBe('I cannot delete tasks.')
        ->and($response->steps->first()->toolResults[0]->result)->toStartWith("Tool 'delete-task' does not exist.");

    $this->assertModelExists($this->boards->acmeTask);
});
