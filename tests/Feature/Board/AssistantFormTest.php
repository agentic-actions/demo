<?php

use AgenticActions\Events\ActionCompleted;
use App\Enums\TaskPriority;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\DB;
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
 * "Add a task for Marcus to review the pricing page" through POST /{team}/assistant, in demo mode. The planner calls
 * create-task with the title and the assignee only; a copilot's task needs a priority and a due date, so the call
 * pauses on a form the server builds, and nothing is added until the person submits it from their own session, in
 * their own team. The model reads which fields they filled, never what they wrote.
 */

beforeEach(function () {
    $this->boards = Boards::make();

    // Each step's messages as the model receives them.
    $this->steps = [];

    Event::listen(StartingStep::class, function (StartingStep $event): void {
        $this->steps[] = $event->messages;
    });

    // Every create-task that ran to the end.
    $this->creates = 0;

    Event::listen(ActionCompleted::class, function (ActionCompleted $event): void {
        $this->creates += $event->action === 'create-task' ? 1 : 0;
    });

    $this->post = function (User $user, array $message, string $team = 'acme', array $headers = []): TestResponse {
        // A new request as the browser sends it: one person's session, and a fresh set of request-scoped services.
        $this->app['auth']->forgetGuards();
        $this->app->forgetScopedInstances();

        return $this->actingAs($user)->json(
            'POST',
            route('assistant', ['current_team' => $team]),
            ['messages' => [$message], 'page' => ['url' => "/{$team}/board", 'component' => 'board']],
            ['Accept' => 'application/json, text/event-stream', ...$headers],
        );
    };

    // Ask for the task, and return the form's call id, the message it continues and the form's part.
    $this->ask = function (User $user, string $words = 'Add a task for Marcus to review the pricing page'): array {
        $response = ($this->post)($user, ['id' => 'm1', 'role' => 'user', 'parts' => [['type' => 'text', 'text' => $words]]]);

        $parts = StreamParts::of($response->assertOk()->streamedContent());
        $request = collect($parts)->firstWhere('type', 'tool-approval-request');

        return [
            'parts' => $parts,
            'call' => $request['toolCallId'] ?? null,
            'message' => collect($parts)->firstWhere('type', 'start')['messageId'] ?? null,
            'form' => collect($parts)->firstWhere('type', 'data-elicitation'),
        ];
    };

    // Answer the form, as useChat posts it through the package's transport: the ElicitResult on the answered tool part.
    $this->answer = fn (User $user, array $form, array $result, string $team = 'acme', array $headers = []): TestResponse => ($this->post)($user, [
        'id' => $form['message'],
        'role' => 'assistant',
        'parts' => [[
            'type' => 'tool-create-task',
            'toolCallId' => $form['call'],
            'state' => 'approval-responded',
            'approval' => ['id' => $form['call'], 'approved' => $result['action'] === 'accept'],
            'elicitation' => $result,
        ]],
    ], $team, $headers);

    // What the person fills in: a title of their own, which the model must never read.
    $this->filled = [
        'action' => 'accept',
        'content' => [
            'title' => 'Review the pricing page copy xq7-canary',
            'priority' => 'high',
            'due_on' => '2031-02-14',
            'assignee' => 'Marcus Reed',
        ],
    ];
});

/**
 * The tool results the model read in the last step of the turn.
 *
 * @param  list<array<int, Message>>  $steps
 * @return list<string>
 */
function toolResultsAfterForm(array $steps): array
{
    return collect(end($steps) ?: [])
        ->filter(fn (Message $message): bool => $message instanceof ToolResultMessage)
        ->flatMap(fn (ToolResultMessage $message) => $message->toolResults->map->text())
        ->values()
        ->all();
}

it('pauses on a form built on the server, with the title and Marcus filled in, and adds nothing yet', function () {
    $before = Task::query()->count();

    $form = ($this->ask)($this->boards->owner);

    expect($form['call'])->toBeString()
        ->and(StreamParts::rows($form['parts']))->toBe([])
        ->and(collect($form['parts'])->firstWhere('type', 'tool-input-available')['input'])->toBe([])
        ->and($form['form']['id'])->toBe("elicitation:{$form['call']}")
        ->and($form['form']['data'])->toBe([
            'action' => 'create-task',
            'params' => [
                'mode' => 'form',
                'message' => 'Give this task a due date and a priority, and I\'ll add it.',
                'requestedSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'title' => [
                            'type' => 'string',
                            'title' => 'Title',
                            'description' => 'A short title, such as "Draft the launch post".',
                            'minLength' => 1,
                            'maxLength' => 120,
                            'default' => 'Review the pricing page',
                        ],
                        'priority' => [
                            'type' => 'string',
                            'title' => 'Priority',
                            'description' => 'How urgent it is: low, normal or high.',
                            'oneOf' => [
                                ['const' => 'low', 'title' => 'Low'],
                                ['const' => 'normal', 'title' => 'Normal'],
                                ['const' => 'high', 'title' => 'High'],
                            ],
                            'default' => 'normal',
                        ],
                        'due_on' => [
                            'type' => 'string',
                            'title' => 'Due date',
                            'description' => 'The day it should be done by.',
                            'format' => 'date',
                        ],
                        'assignee' => [
                            'type' => 'string',
                            'title' => 'Assignee',
                            'description' => 'Who does it: a member of this team, by email or full name.',
                            'oneOf' => [
                                ['const' => 'Ada Admin', 'title' => 'Ada Admin'],
                                ['const' => 'Marcus Reed', 'title' => 'Marcus Reed'],
                                ['const' => 'Olivia Park', 'title' => 'Olivia Park'],
                                ['const' => 'Vera Lind', 'title' => 'Vera Lind'],
                            ],
                            'default' => 'Marcus Reed',
                        ],
                    ],
                    // requiredForAgents() marks the priority and the due date required, as the model's call needed them.
                    'required' => ['title', 'priority', 'due_on'],
                ],
            ],
            'labels' => [
                'source' => 'Asked by '.config('app.name'),
                'submit' => 'Submit',
                'decline' => 'Decline',
                'cancel' => 'Not now',
            ],
        ]);

    expect(Task::query()->count())->toBe($before)
        ->and($this->creates)->toBe(0);
});

it('adds the task once, with the person\'s values, and the model reads only which fields they filled', function () {
    $form = ($this->ask)($this->boards->owner);

    // The page checks the answer first, with a Precognition request: 204, and nothing runs.
    ($this->answer)($this->boards->owner, $form, $this->filled, headers: ['Precognition' => 'true'])->assertNoContent();

    expect($this->creates)->toBe(0);

    $parts = StreamParts::of(($this->answer)($this->boards->owner, $form, $this->filled)->assertOk()->streamedContent());

    expect(StreamParts::rows($parts))->toBe([
        ['action' => 'create-task', 'label' => 'Created a task', 'status' => 'done', 'effect' => 'write', 'touches' => ['tasks', 'summary']],
    ])
        ->and(collect($parts)->firstWhere('type', 'start')['messageId'])->toBe($form['message'])
        ->and(StreamParts::text($parts))->toBe("Done. I added the task with the details you filled in, and it's in To do.")
        ->and(toolResultsAfterForm($this->steps))->toBe(['The person filled in: title, priority, due_on, assignee. Done.'])
        ->and($this->creates)->toBe(1);

    $task = Task::query()->where('title', 'Review the pricing page copy xq7-canary')->sole();

    expect($task->team_id)->toBe($this->boards->acme->id)
        ->and($task->created_by)->toBe($this->boards->owner->id)
        ->and($task->assignee_id)->toBe($this->boards->member->id)
        ->and($task->priority)->toBe(TaskPriority::High)
        ->and($task->due_on->toDateString())->toBe('2031-02-14');

    // Nothing the person wrote reached the model, nor the conversation laravel/ai keeps for it: the stored call holds
    // the model's own arguments.
    $sent = json_encode($this->steps, JSON_THROW_ON_ERROR);
    $stored = DB::table('agent_conversation_messages')->get()->toJson();

    expect($sent)->not->toContain('xq7-canary')->not->toContain('2031-02-14')
        ->and($stored)->not->toContain('xq7-canary')->not->toContain('2031-02-14')
        ->and($stored)->toContain('Review the pricing page');

    // The answer is spent: sending it again runs nothing.
    ($this->answer)($this->boards->owner, $form, $this->filled)->assertStatus(409);

    expect($this->creates)->toBe(1);
});

it('answers 422 by field when the action\'s rules refuse the answer, adds nothing, and keeps the form waiting', function () {
    $before = Task::query()->count();
    $form = ($this->ask)($this->boards->owner);

    $invalid = ['action' => 'accept', 'content' => ['title' => 'Review the pricing page', 'priority' => 'urgent', 'due_on' => 'soon']];

    ($this->answer)($this->boards->owner, $form, $invalid, headers: ['Precognition' => 'true'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['priority', 'due_on'])
        ->assertJsonMissingValidationErrors(['title', 'assignee']);

    // Left empty, a copilot's due date is still required.
    ($this->answer)($this->boards->owner, $form, ['action' => 'accept', 'content' => ['title' => 'Review the pricing page', 'priority' => 'normal']], headers: ['Precognition' => 'true'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['due_on' => 'The due date field is required.']);

    expect(Task::query()->count())->toBe($before)
        ->and($this->creates)->toBe(0);

    ($this->answer)($this->boards->owner, $form, $this->filled)->assertOk()->streamedContent();

    expect($this->creates)->toBe(1);
});

it('adds nothing when the person declines or closes the form, and tells the model which', function (string $action, string $read, string $reply) {
    $before = Task::query()->count();
    $form = ($this->ask)($this->boards->owner);

    $parts = StreamParts::of(($this->answer)($this->boards->owner, $form, ['action' => $action])->assertOk()->streamedContent());

    expect(StreamParts::rows($parts))->toBe([
        ['action' => 'create-task', 'label' => 'Declined', 'status' => 'declined'],
    ])
        ->and(toolResultsAfterForm($this->steps))->toBe([$read])
        ->and(StreamParts::text($parts))->toBe($reply)
        ->and(Task::query()->count())->toBe($before)
        ->and($this->creates)->toBe(0);

    // The answer is final: submitting the same form afterwards runs nothing.
    ($this->answer)($this->boards->owner, $form, $this->filled)->assertStatus(409);

    expect($this->creates)->toBe(0);
})->with([
    'decline' => ['decline', 'The person declined to fill in title, priority, due_on, assignee, so nothing ran. Do not ask again unless they ask.', "Okay, I didn't add it."],
    'not now' => ['cancel', 'The person closed the form without answering, so nothing ran. Offer it again only if it still matters.', 'No problem, I left it for now. Ask me again when you have the details.'],
]);

it('lets nobody else answer the owner\'s form: not another Acme member, not Marcus, and not Globex', function () {
    $form = ($this->ask)($this->boards->owner);

    // Other people in Acme: their own conversations are not waiting on this form.
    ($this->answer)($this->boards->admin, $form, $this->filled)->assertStatus(409);
    ($this->answer)($this->boards->member, $form, $this->filled)->assertStatus(409);

    // Globex's owner, on Acme's endpoint: not a member of Acme.
    ($this->answer)($this->boards->globexOwner, $form, $this->filled)->assertForbidden();

    // On Globex's endpoint, Globex's owner and Marcus (a member of both teams): nothing there waits on this form.
    ($this->answer)($this->boards->globexOwner, $form, $this->filled, 'globex')->assertStatus(409);
    ($this->answer)($this->boards->member, $form, $this->filled, 'globex')->assertStatus(409);

    expect($this->creates)->toBe(0);
    $this->assertDatabaseMissing('tasks', ['title' => 'Review the pricing page copy xq7-canary']);

    // The form still works for the person it was shown to.
    ($this->answer)($this->boards->owner, $form, $this->filled)->assertOk()->streamedContent();

    expect($this->creates)->toBe(1)
        ->and(Task::query()->where('title', 'Review the pricing page copy xq7-canary')->value('team_id'))->toBe($this->boards->acme->id);
});

it('restores a waiting form on reload, rebuilt on the server, and answering it works', function () {
    $form = ($this->ask)($this->boards->owner);

    $transcript = fn () => $this->actingAs($this->boards->owner)
        ->getJson(route('assistant.transcript', ['current_team' => 'acme']))
        ->assertOk()
        ->json('messages');

    $paused = collect($transcript())->last();

    expect($paused['role'])->toBe('assistant')
        ->and($paused['parts'])->toContain([
            'type' => 'tool-create-task',
            'toolCallId' => $form['call'],
            'state' => 'approval-requested',
            'input' => [],
            'approval' => ['id' => $form['call']],
        ])
        ->and(collect($paused['parts'])->firstWhere('type', 'data-elicitation'))->toBe($form['form']);

    // After a reload the panel continues the stored message, by its stored id.
    $response = ($this->answer)($this->boards->owner, ['call' => $form['call'], 'message' => $paused['id']], $this->filled);

    expect(collect(StreamParts::of($response->assertOk()->streamedContent()))->firstWhere('type', 'start')['messageId'])->toBe($paused['id'])
        ->and($this->creates)->toBe(1)
        ->and(collect($transcript())->flatMap(fn (array $message): array => $message['parts'])->pluck('type'))->not->toContain('data-elicitation');
});

it('runs a complete call at once: no form when the model gives a priority and a due date', function () {
    $parts = StreamParts::of(($this->post)($this->boards->owner, [
        'id' => 'm1', 'role' => 'user', 'parts' => [['type' => 'text', 'text' => 'Add three launch tasks for Marcus']],
    ])->assertOk()->streamedContent());

    expect(collect($parts)->pluck('type'))->not->toContain('data-elicitation')
        ->and($this->creates)->toBe(3);
});

it('pauses and resumes the same way for a real model once a key is set', function () {
    config(['ai.providers.openrouter.key' => 'test-key']);

    // A model on the provider's own gateway, as OpenRouter's would be: it calls create-task with what it knows.
    Ai::textProvider()->useTextGateway(new FakeTextGateway([
        new ToolCall('call_1', 'create-task', ['title' => 'Review the pricing page', 'assignee' => 'Marcus Reed']),
        'Added it.',
    ]));

    $form = ($this->ask)($this->boards->owner);

    expect($form['call'])->toBe('call_1')
        ->and($form['form']['data']['params']['requestedSchema']['properties']['assignee']['default'])->toBe('Marcus Reed')
        ->and($this->creates)->toBe(0);

    $parts = StreamParts::of(($this->answer)($this->boards->owner, $form, $this->filled)->assertOk()->streamedContent());

    expect(StreamParts::text($parts))->toBe('Added it.')
        ->and($this->creates)->toBe(1);
});
