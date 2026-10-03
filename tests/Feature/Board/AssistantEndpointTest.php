<?php

use AgenticActions\Facades\Actions;
use AgenticActions\Streaming\AgenticConversation;
use App\Ai\Agents\BoardAssistant;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use Laravel\Ai\Events\StartingStep;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Messages\MessageRole;
use Laravel\Ai\Messages\ToolResultMessage;
use Laravel\Ai\Responses\Data\ToolCall;
use Tests\Support\Boards;
use Tests\Support\StreamParts;

/*
 * POST /{team}/assistant and GET /{team}/assistant/transcript through the real kernel. With no OpenRouter key (the
 * tests force it empty) the assistant runs in demo mode: laravel/ai's fake gateway, driven by the scripted planner,
 * through the same ActionsProtocol, actions, authorization and conversation store as a real model. No key, no network.
 */

beforeEach(function () {
    $this->boards = Boards::make();

    // Each step's messages as the provider receives them, after middleware.
    $this->steps = [];

    Event::listen(StartingStep::class, function (StartingStep $event): void {
        $this->steps[] = $event->messages;
    });

    // One turn, as the /ai-sdk preset posts it: the newest message, and the page the person has open.
    $this->send = fn (User $user, string $words, string $team = 'acme', ?array $page = null): TestResponse => $this->actingAs($user)->json(
        'POST',
        route('assistant', ['current_team' => $team]),
        [
            'id' => 'assistant',
            'messages' => [['id' => 'm1', 'role' => 'user', 'parts' => [['type' => 'text', 'text' => $words]]]],
            'trigger' => 'submit-message',
            'page' => $page ?? ['url' => "/{$team}/board", 'component' => 'board'],
        ],
        ['Accept' => 'application/json, text/event-stream'],
    );

    // The parts of one turn.
    $this->turn = function (User $user, string $words, string $team = 'acme', ?array $page = null): array {
        $response = ($this->send)($user, $words, $team, $page);

        $response->assertOk();

        return StreamParts::of($response->streamedContent());
    };
});

afterEach(function () {
    ignore_user_abort(false);
});

/**
 * The tool results the model read in the last step of the turn.
 *
 * @param  list<array<int, Message>>  $steps
 * @return list<string>
 */
function toolResultsRead(array $steps): array
{
    return collect(end($steps) ?: [])
        ->filter(fn (Message $message): bool => $message instanceof ToolResultMessage)
        ->flatMap(fn (ToolResultMessage $message) => $message->toolResults->map->text())
        ->values()
        ->all();
}

it('streams the rows and the reply, and never a tool part', function () {
    Task::factory()->create([
        'team_id' => $this->boards->acme->id,
        'title' => 'Fix the pricing links',
        'status' => TaskStatus::Todo,
        'priority' => TaskPriority::High,
        'due_on' => today()->subDays(2),
        'assignee_id' => $this->boards->member->id,
    ]);

    $response = ($this->send)($this->boards->owner, "What's overdue?");

    $response->assertOk();

    expect($response->headers->get('Content-Type'))->toBe('text/event-stream; charset=utf-8');

    $parts = StreamParts::of($response->streamedContent());

    expect(StreamParts::types($parts))->toBe([
        'start', 'start-step',
        'data-action', 'data-action',
        'finish-step', 'start-step',
        'text-start', ...array_fill(0, count(array_filter($parts, fn ($part) => is_array($part) && $part['type'] === 'text-delta')), 'text-delta'), 'text-end',
        'finish-step', 'finish',
        '[DONE]',
    ])
        ->and(StreamParts::runningLabels($parts))->toBe(['Listing tasks…'])
        ->and(StreamParts::rows($parts))->toBe([
            ['action' => 'list-tasks', 'label' => 'Listed tasks', 'status' => 'done', 'effect' => 'read'],
        ])
        ->and(StreamParts::text($parts))->toBe("**One task** is overdue:\n\n- Fix the pricing links (Marcus Reed, due ".today()->subDays(2)->format('j M').')');

    // The allowlist: no tool-call parts, arguments or results, no reasoning, no model names.
    expect(collect(StreamParts::types($parts))->filter(fn (string $type): bool => str_starts_with($type, 'tool-') || str_starts_with($type, 'reasoning')))->toBeEmpty()
        ->and($response->streamedContent())->not->toContain('overdue":true')
        ->and($response->streamedContent())->not->toContain('claude');
});

it('ticks "Creating a task…" to "Created a task" for each task, and touches the board', function () {
    $parts = ($this->turn)($this->boards->owner, 'Add three launch tasks for Marcus');

    $rows = StreamParts::rows($parts);

    // "Marcus" is no member's full name or email: the first call is refused with the members listed, and the planner
    // carries on with the one it meant, as a model reading that answer would.
    expect($rows)->toHaveCount(4)
        ->and($rows[0])->toBe(['action' => 'create-task', 'label' => 'Creating a task…', 'status' => 'refused', 'effect' => 'write', 'note' => 'Not done'])
        ->and(array_slice($rows, 1))->each->toBe(['action' => 'create-task', 'label' => 'Created a task', 'status' => 'done', 'effect' => 'write', 'touches' => ['tasks', 'summary']])
        ->and(StreamParts::runningLabels($parts))->toBe(array_fill(0, 4, 'Creating a task…'))
        ->and(StreamParts::text($parts))->toBe('I added 3 tasks for Marcus Reed: “Draft the launch checklist”, “Brief the support team on the launch” and “Schedule the launch social posts”. They are in To do.');

    $added = Task::query()->where('title', 'like', '%launch%')->where('id', '!=', $this->boards->acmeTask->id)->get();

    expect($added)->toHaveCount(3)
        ->and($added->pluck('team_id')->unique()->all())->toBe([$this->boards->acme->id])
        ->and($added->pluck('assignee_id')->unique()->all())->toBe([$this->boards->member->id])
        ->and($added->pluck('created_by')->unique()->all())->toBe([$this->boards->owner->id]);
});

it('completes a task named in quotes', function () {
    $parts = ($this->turn)($this->boards->member, "Move 'Write the launch announcement' to done");

    expect(StreamParts::rows($parts))->toBe([
        ['action' => 'complete-task', 'label' => 'Moved a task to done', 'status' => 'done', 'effect' => 'write', 'touches' => ['tasks', 'summary']],
    ])
        ->and(StreamParts::text($parts))->toBe('Done. “Write the launch announcement” is in the Done column now.')
        ->and($this->boards->acmeTask->fresh()->status)->toBe(TaskStatus::Done);
});

it('shows a refused row for a write the action refuses, and changes nothing', function () {
    // Marcus is in both teams, but this turn runs in Acme: Globex's task is not an open task here.
    $parts = ($this->turn)($this->boards->member, "Move 'Cancel the Elm Street lease' to done");

    expect(StreamParts::rows($parts))->toBe([
        ['action' => 'complete-task', 'label' => 'Moving a task to done…', 'status' => 'refused', 'effect' => 'write', 'note' => 'Not done'],
    ])
        ->and(StreamParts::text($parts))->toBe('I couldn\'t move “Cancel the Elm Street lease”: No open task has that title.')
        ->and($this->boards->globexTask->fresh()->status)->toBe(TaskStatus::Todo);
});

it('gives a viewer no write tool: their write runs no row, changes nothing, and the assistant says why', function () {
    $before = Task::query()->count();

    $parts = ($this->turn)($this->boards->viewer, 'Add three launch tasks for Marcus');

    expect(StreamParts::rows($parts))->toBe([])
        ->and(StreamParts::text($parts))->toBe("I can't add tasks here. You're a Viewer in Acme, and I only get the tools your role allows, so nothing changed.")
        ->and(toolResultsRead($this->steps))->toBe(["Tool 'create-task' does not exist. Available tools: completed-per-day, completion-stats, list-tasks, search-tasks, summarize-board, task-stats, tasks-by-assignee, tasks-by-status."])
        ->and(Task::query()->count())->toBe($before);
});

it('answers a plain reply when no script matches, with no rows', function () {
    $parts = ($this->turn)($this->boards->owner, 'Hello there');

    expect(StreamParts::rows($parts))->toBe([])
        ->and(StreamParts::text($parts))->toStartWith("I'm in demo mode");
});

it('tells the model which page is open, and nothing about another team\'s page', function () {
    ($this->turn)($this->boards->owner, 'How are we doing?');

    $lastUserWords = fn (): string => (string) collect(end($this->steps))->last(fn (Message $message): bool => $message->role === MessageRole::User)->content;

    expect($lastUserWords())->toContain('The person has this page open: board (board).');

    $this->steps = [];

    ($this->turn)($this->boards->member, 'How are we doing?', 'acme', ['url' => '/globex/board', 'component' => 'board']);

    expect($lastUserWords())->not->toContain('The person has this page open');
});

it('answers 422 with one sentence when there are no words to send', function () {
    $this->actingAs($this->boards->owner)
        ->json('POST', route('assistant', ['current_team' => 'acme']), ['messages' => [['id' => 'm1', 'role' => 'user', 'parts' => []]]])
        ->assertStatus(422)
        ->assertExactJson(['message' => 'Write a message of up to 4000 characters.']);

    expect(AgenticConversation::query()->count())->toBe(0);
});

it('keeps a conversation per user and team, and remembers it on the next turn', function () {
    ($this->turn)($this->boards->member, 'How are we doing?');

    $this->steps = [];

    ($this->turn)($this->boards->member, "What's overdue?");

    // The second turn's history holds the first turn's words.
    expect(collect($this->steps[0])->pluck('content')->filter()->implode(' '))->toContain('How are we doing?');

    ($this->turn)($this->boards->member, 'How are we doing?', 'globex');

    $acme = Actions::conversation(BoardAssistant::class, $this->boards->member, $this->boards->acme)->id();
    $globex = Actions::conversation(BoardAssistant::class, $this->boards->member, $this->boards->globex)->id();

    expect($acme)->not->toBeNull()
        ->and($globex)->not->toBeNull()->not->toBe($acme)
        ->and(AgenticConversation::query()->whereMorphedTo('participant', $this->boards->member)->count())->toBe(2);
});

it('returns only the user\'s own conversation in this team from the transcript endpoint', function () {
    ($this->turn)($this->boards->member, 'How are we doing?');

    $transcript = fn (User $user, string $team): TestResponse => $this->actingAs($user)
        ->getJson(route('assistant.transcript', ['current_team' => $team]))
        ->assertOk();

    $acme = $transcript($this->boards->member, 'acme')->json('messages');

    expect(array_map(fn (array $message): array => [$message['role'], $message['parts'][0]['text']], $acme))->toBe([
        ['user', 'How are we doing?'],
        ['assistant', '1 tasks: 1 to do, 0 doing, 0 done. 0 overdue, 1 due in the next seven days, 0 open and unassigned.'],
    ]);

    // The same person in another team, and another person in the same team, see nothing of it.
    $transcript($this->boards->member, 'globex')->assertExactJson(['messages' => [], 'demo' => true, 'max_length' => 4000]);
    $transcript($this->boards->owner, 'acme')->assertExactJson(['messages' => [], 'demo' => true, 'max_length' => 4000]);
});

it('keeps a Globex user away from Acme\'s assistant and its conversations', function () {
    ($this->turn)($this->boards->owner, 'How are we doing?');

    ($this->send)($this->boards->globexOwner, 'How are we doing?', 'acme')->assertForbidden();

    $this->actingAs($this->boards->globexOwner)
        ->getJson(route('assistant.transcript', ['current_team' => 'acme']))
        ->assertForbidden();

    expect(AgenticConversation::query()->whereMorphedTo('participant', $this->boards->globexOwner)->exists())->toBeFalse();
});

it('runs a real model\'s tool calls through the same stream once a key is set', function () {
    config(['ai.providers.openrouter.key' => 'test-key']);

    BoardAssistant::fake([
        new ToolCall('call_1', 'create-task', ['title' => 'Book the photographer', 'assignee' => 'Marcus Reed', 'priority' => 'normal', 'due_on' => today()->addWeek()->toDateString()]),
        'Created it.',
    ]);

    $parts = ($this->turn)($this->boards->owner, 'Ask Marcus to book the photographer.');

    expect(StreamParts::rows($parts))->toBe([
        ['action' => 'create-task', 'label' => 'Created a task', 'status' => 'done', 'effect' => 'write', 'touches' => ['tasks', 'summary']],
    ])
        ->and(StreamParts::text($parts))->toBe('Created it.')
        ->and(Task::query()->where('title', 'Book the photographer')->value('assignee_id'))->toBe($this->boards->member->id);

    $this->actingAs($this->boards->owner)
        ->getJson(route('assistant.transcript', ['current_team' => 'acme']))
        ->assertJsonPath('demo', false);
});
