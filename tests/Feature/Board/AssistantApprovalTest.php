<?php

use AgenticActions\Events\ActionCompleted;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use Laravel\Ai\Ai;
use Laravel\Ai\Events\StartingStep;
use Laravel\Ai\Gateway\FakeTextGateway;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Messages\ToolResultMessage;
use Laravel\Ai\Responses\Data\ToolCall;
use Tests\Support\Boards;
use Tests\Support\StreamParts;

/*
 * "Delete 'Write the launch announcement'" through POST /{team}/assistant, in demo mode: the planner plays the model on
 * the provider's gateway, so a confirmed delete resumes on the path a real model's does. delete-task pauses on a card
 * the server builds; nothing runs until the person answers it from their own session, in their own team.
 */

beforeEach(function () {
    $this->boards = Boards::make();

    // Each step's messages as the model receives them.
    $this->steps = [];

    Event::listen(StartingStep::class, function (StartingStep $event): void {
        $this->steps[] = $event->messages;
    });

    // Every delete-task that ran to the end.
    $this->deletes = 0;

    Event::listen(ActionCompleted::class, function (ActionCompleted $event): void {
        $this->deletes += $event->action === 'delete-task' ? 1 : 0;
    });

    $this->post = function (User $user, array $message, string $team = 'acme'): TestResponse {
        // A new request as the browser sends it: one person's session, and a fresh set of request-scoped services.
        $this->app['auth']->forgetGuards();
        $this->app->forgetScopedInstances();

        return $this->actingAs($user)->json(
            'POST',
            route('assistant', ['current_team' => $team]),
            ['messages' => [$message], 'page' => ['url' => "/{$team}/board", 'component' => 'board']],
            ['Accept' => 'application/json, text/event-stream'],
        );
    };

    // Ask for the delete, and return the card's call id and the message it continues.
    $this->ask = function (User $user, string $title): array {
        $response = ($this->post)($user, ['id' => 'm1', 'role' => 'user', 'parts' => [['type' => 'text', 'text' => "Delete '{$title}'"]]]);

        $response->assertOk();

        $parts = StreamParts::of($response->streamedContent());
        $request = collect($parts)->firstWhere('type', 'tool-approval-request');

        return [
            'parts' => $parts,
            'call' => $request['toolCallId'] ?? null,
            'message' => collect($parts)->firstWhere('type', 'start')['messageId'] ?? null,
        ];
    };

    // Answer the card, as useChat posts it through the package's transport.
    $this->answer = fn (User $user, array $card, bool $approved, string $team = 'acme'): TestResponse => ($this->post)($user, [
        'id' => $card['message'],
        'role' => 'assistant',
        'parts' => [[
            'type' => 'tool-delete-task',
            'toolCallId' => $card['call'],
            'state' => 'approval-responded',
            'approval' => ['id' => $card['call'], 'approved' => $approved],
        ]],
    ], $team);
});

/**
 * The tool results the model read in the last step of the turn.
 *
 * @param  list<array<int, Message>>  $steps
 * @return list<string>
 */
function toolResultsReadLast(array $steps): array
{
    return collect(end($steps) ?: [])
        ->filter(fn (Message $message): bool => $message instanceof ToolResultMessage)
        ->flatMap(fn (ToolResultMessage $message) => $message->toolResults->map->text())
        ->values()
        ->all();
}

it('pauses the delete on a card built from the task, and deletes nothing yet', function () {
    $card = ($this->ask)($this->boards->owner, 'Write the launch announcement');

    $approval = collect($card['parts'])->firstWhere('type', 'data-approval');

    expect($card['call'])->toBeString()
        ->and(StreamParts::rows($card['parts']))->toBe([
            ['action' => 'search-tasks', 'label' => 'Searched tasks', 'status' => 'done', 'effect' => 'read'],
        ])
        ->and($approval['id'])->toBe("approval:{$card['call']}")
        ->and($approval['data'])->toBe([
            'action' => 'delete-task',
            'effect' => 'destructive',
            'label' => 'Waiting for your confirmation',
            'title' => 'Delete this task? This cannot be undone.',
            'summary' => [
                ['label' => 'Task', 'value' => 'Write the launch announcement'],
                ['label' => 'Project', 'value' => 'Website relaunch'],
                ['label' => 'Assignee', 'value' => 'Olivia Park'],
            ],
            'confirm' => 'Confirm',
            'decline' => 'Decline',
        ])
        ->and(collect($card['parts'])->firstWhere('type', 'tool-input-available')['input'])->toBe([]);

    $this->assertModelExists($this->boards->acmeTask);
    expect($this->deletes)->toBe(0);
});

it('deletes the task once when the owner confirms, and the board reloads', function () {
    $card = ($this->ask)($this->boards->owner, 'Write the launch announcement');

    $response = ($this->answer)($this->boards->owner, $card, true);

    $response->assertOk();

    $parts = StreamParts::of($response->streamedContent());

    expect(StreamParts::rows($parts))->toBe([
        ['action' => 'delete-task', 'label' => 'Deleted a task', 'status' => 'done', 'effect' => 'destructive', 'touches' => ['tasks', 'summary']],
    ])
        ->and(collect($parts)->firstWhere('type', 'start')['messageId'])->toBe($card['message'])
        ->and(collect($parts)->firstWhere('type', 'tool-output-available'))->toBe(['type' => 'tool-output-available', 'toolCallId' => $card['call'], 'output' => null])
        ->and(StreamParts::text($parts))->toBe('Deleted “Write the launch announcement”. It\'s off the board.')
        ->and($this->deletes)->toBe(1);

    $this->assertModelMissing($this->boards->acmeTask);
});

it('deletes nothing when the owner declines, and tells the model', function () {
    $card = ($this->ask)($this->boards->owner, 'Write the launch announcement');

    $response = ($this->answer)($this->boards->owner, $card, false);

    $response->assertOk();

    $parts = StreamParts::of($response->streamedContent());

    expect(StreamParts::rows($parts))->toBe([
        ['action' => 'delete-task', 'label' => 'Declined', 'status' => 'declined'],
    ])
        ->and(toolResultsReadLast($this->steps))->toContain('The person declined this call, so it did not run. Do not try it again unless they ask.')
        ->and(StreamParts::text($parts))->toBe('Okay, I left “Write the launch announcement” on the board.')
        ->and($this->deletes)->toBe(0);

    $this->assertModelExists($this->boards->acmeTask);

    // The decline is final: confirming the same card afterwards runs nothing.
    ($this->answer)($this->boards->owner, $card, true)->assertStatus(409);

    $this->assertModelExists($this->boards->acmeTask);
});

it('answers a second confirm with 409 and runs nothing more', function () {
    $card = ($this->ask)($this->boards->owner, 'Write the launch announcement');

    ($this->answer)($this->boards->owner, $card, true)->assertOk()->streamedContent();

    ($this->answer)($this->boards->owner, $card, true)
        ->assertStatus(409)
        ->assertExactJson(['message' => 'That confirmation is no longer waiting, so nothing ran again.']);

    expect($this->deletes)->toBe(1);
});

it('restores a waiting card on reload, rebuilt on the server, and answering it works', function () {
    $card = ($this->ask)($this->boards->owner, 'Write the launch announcement');

    $transcript = fn () => $this->actingAs($this->boards->owner)
        ->getJson(route('assistant.transcript', ['current_team' => 'acme']))
        ->assertOk()
        ->json('messages');

    $paused = collect($transcript())->last();

    expect($paused['role'])->toBe('assistant')
        ->and($paused['parts'])->toContain([
            'type' => 'tool-delete-task',
            'toolCallId' => $card['call'],
            'state' => 'approval-requested',
            'input' => [],
            'approval' => ['id' => $card['call']],
        ])
        ->and(collect($paused['parts'])->firstWhere('type', 'data-approval')['data']['summary'][0])
        ->toBe(['label' => 'Task', 'value' => 'Write the launch announcement']);

    // After a reload the panel continues the stored message, by its stored id.
    $response = ($this->answer)($this->boards->owner, ['call' => $card['call'], 'message' => $paused['id']], true);

    expect(collect(StreamParts::of($response->assertOk()->streamedContent()))->firstWhere('type', 'start')['messageId'])->toBe($paused['id']);

    $this->assertModelMissing($this->boards->acmeTask);

    expect(collect($transcript())->flatMap(fn (array $message): array => $message['parts'])->pluck('type'))
        ->not->toContain('data-approval');
});

it('never offers delete-task to a member or a viewer: no card, nothing deleted, and the assistant says why', function (string $who, string $role) {
    $card = ($this->ask)($this->boards->{$who}, 'Write the launch announcement');

    expect($card['call'])->toBeNull()
        ->and(collect($card['parts'])->pluck('type'))->not->toContain('data-approval')
        ->and(toolResultsReadLast($this->steps))->toContain("Tool 'delete-task' does not exist. Available tools: ".($who === 'viewer'
            ? 'completed-per-day, completion-stats, list-tasks, search-tasks, summarize-board, task-stats, tasks-by-assignee, tasks-by-status.'
            : 'complete-task, completed-per-day, completion-stats, create-task, list-tasks, search-tasks, summarize-board, task-stats, tasks-by-assignee, tasks-by-status, update-task.'))
        ->and(StreamParts::text($card['parts']))->toBe("I can't delete tasks here. You're a {$role} in Acme, and I only get the tools your role allows, so “Write the launch announcement” is still on the board.");

    $this->assertModelExists($this->boards->acmeTask);
})->with([
    'member' => ['member', 'Member'],
    'viewer' => ['viewer', 'Viewer'],
]);

it('lets nobody else answer the owner\'s card: not another Acme member, and not Globex', function () {
    $card = ($this->ask)($this->boards->owner, 'Write the launch announcement');

    // Another person in Acme: their own conversation is not waiting on this card.
    ($this->answer)($this->boards->admin, $card, true)->assertStatus(409);

    // Globex's owner, on Acme's endpoint: not a member of Acme.
    ($this->answer)($this->boards->globexOwner, $card, true)->assertForbidden();

    // Globex's owner, on Globex's endpoint: nothing there waits on this card.
    ($this->answer)($this->boards->globexOwner, $card, true, 'globex')->assertStatus(409);

    $this->assertModelExists($this->boards->acmeTask);
    expect($this->deletes)->toBe(0);

    // The card still works for the person it was shown to.
    ($this->answer)($this->boards->owner, $card, true)->assertOk()->streamedContent();

    $this->assertModelMissing($this->boards->acmeTask);
});

it('keeps a Globex task out of reach of Acme\'s delete', function () {
    $card = ($this->ask)($this->boards->owner, 'Cancel the Elm Street lease');

    expect($card['call'])->toBeNull()
        ->and(StreamParts::text($card['parts']))->toBe('I couldn\'t find a task called “Cancel the Elm Street lease”.');

    $this->assertModelExists($this->boards->globexTask);
});

it('pauses and resumes the same way for a real model once a key is set', function () {
    config(['ai.providers.openrouter.key' => 'test-key']);

    // A model on the provider's own gateway, as OpenRouter's would be: it calls delete-task, then words its reply.
    Ai::textProvider()->useTextGateway(new FakeTextGateway([
        new ToolCall('call_1', 'delete-task', ['task' => $this->boards->acmeTask->id]),
        'Deleted it.',
    ]));

    $card = ($this->ask)($this->boards->owner, 'Write the launch announcement');

    expect($card['call'])->toBe('call_1')
        ->and(collect($card['parts'])->firstWhere('type', 'data-approval')['data']['summary'][0]['value'])->toBe('Write the launch announcement');

    $this->assertModelExists($this->boards->acmeTask);

    $parts = StreamParts::of(($this->answer)($this->boards->owner, $card, true)->assertOk()->streamedContent());

    expect(StreamParts::text($parts))->toBe('Deleted it.')
        ->and($this->deletes)->toBe(1);

    $this->assertModelMissing($this->boards->acmeTask);
});
