<?php

use App\Enums\TaskPriority;
use App\Enums\TokenAccess;
use App\Models\Task;
use Illuminate\Testing\TestResponse;
use Tests\Support\Boards;

/*
 * create-task over MCP without a priority or a due date. requiredForAgents() offers both to MCP clients as required, so
 * a client that shows no form gets the refusal naming them, and a client on protocol 2026-07-28 that declares form
 * elicitation gets the copilot's form as an InputRequiredResult. Its retry with the answer and the request state adds
 * the task once, and the client's model reads which fields were filled, never the values.
 */

beforeEach(function () {
    $this->boards = Boards::make();
    $this->token = $this->boards->owner->createToken('Claude Code', TokenAccess::ReadWrite->abilities($this->boards->acme))->plainTextToken;
    $this->arguments = ['title' => 'Book the launch venue', 'assignee' => 'Marcus Reed'];
});

/**
 * One tools/call of create-task in Acme from a client on protocol 2026-07-28 that declares form elicitation, as the
 * official TypeScript client sends it. A retry adds inputResponses and the requestState.
 *
 * @param  array<string, mixed>  $arguments
 * @param  array<string, mixed>  $retry
 */
function callFromFormClient(string $token, array $arguments, array $retry = []): TestResponse
{
    $params = [
        'name' => 'create-task',
        'arguments' => $arguments,
        ...$retry,
        '_meta' => [
            'io.modelcontextprotocol/protocolVersion' => '2026-07-28',
            'io.modelcontextprotocol/clientCapabilities' => ['elicitation' => ['form' => new stdClass]],
        ],
    ];

    return test()->postJson(Boards::mcpUrl(test()->boards->acme), Boards::rpc('tools/call', $params), [
        ...Boards::bearer($token),
        'MCP-Protocol-Version' => '2026-07-28',
        'Mcp-Method' => 'tools/call',
        'Mcp-Name' => 'create-task',
    ]);
}

it('offers the priority and the due date to MCP clients as required and not nullable', function () {
    $tools = collect($this->postJson(Boards::mcpUrl($this->boards->acme), Boards::rpc('tools/list'), Boards::bearer($this->token))
        ->assertOk()
        ->json('result.tools'))->keyBy('name');

    $input = $tools['create-task']['inputSchema'];

    expect($input['required'])->toBe(['title', 'priority', 'due_on'])
        ->and($input['properties']['priority']['type'])->toBe('string')
        ->and($input['properties']['due_on']['type'])->toBe('string');
});

it('refuses a call without them from a client that shows no form, naming both, and adds nothing', function () {
    $this->postJson(Boards::mcpUrl($this->boards->acme), Boards::rpc('tools/call', [
        'name' => 'create-task',
        'arguments' => $this->arguments,
    ]), Boards::bearer($this->token))
        ->assertOk()
        ->assertJsonPath('result.isError', true)
        ->assertJsonPath('result.content.0.text', 'Not done. Rejected: priority (required), due_on (required).');

    $this->assertDatabaseMissing('tasks', ['title' => 'Book the launch venue']);
});

it('shows a client that declares form elicitation the copilot\'s form, and adds nothing yet', function () {
    $response = callFromFormClient($this->token, $this->arguments)->assertOk();

    $params = $response->json('result.inputRequests.create-task.params');

    expect($response->json('result.resultType'))->toBe('input_required')
        ->and($response->json('result.inputRequests.create-task.method'))->toBe('elicitation/create')
        ->and($response->json('result.requestState'))->toBeString()
        ->and($params['message'])->toBe('Give this task a due date and a priority, and I\'ll add it.')
        ->and($params['requestedSchema']['required'])->toBe(['title', 'priority', 'due_on'])
        ->and($params['requestedSchema']['properties']['title']['default'])->toBe('Book the launch venue')
        ->and($params['requestedSchema']['properties']['assignee']['default'])->toBe('Marcus Reed')
        ->and($params['requestedSchema']['properties']['priority']['default'])->toBe('normal')
        ->and($params['requestedSchema']['properties']['due_on'])->not->toHaveKey('default');

    $this->assertDatabaseMissing('tasks', ['title' => 'Book the launch venue']);
});

it('adds the task once with the client\'s answer, and its model reads only which fields were filled', function () {
    $state = callFromFormClient($this->token, $this->arguments)->json('result.requestState');

    $response = callFromFormClient($this->token, $this->arguments, [
        'inputResponses' => ['create-task' => ['action' => 'accept', 'content' => [
            'title' => 'Book the launch venue',
            'priority' => 'high',
            'due_on' => '2031-02-14',
            'assignee' => 'Marcus Reed',
        ]]],
        'requestState' => $state,
    ])->assertOk();

    expect($response->json('result.isError'))->toBeFalse()
        ->and($response->json('result.content'))->toBe([['type' => 'text', 'text' => 'The person filled in: title, priority, due_on, assignee. Done.']]);

    $task = Task::query()->where('title', 'Book the launch venue')->sole();

    expect($task->team_id)->toBe($this->boards->acme->id)
        ->and($task->created_by)->toBe($this->boards->owner->id)
        ->and($task->assignee_id)->toBe($this->boards->member->id)
        ->and($task->priority)->toBe(TaskPriority::High)
        ->and($task->due_on->toDateString())->toBe('2031-02-14');
});

it('adds nothing when the client declines the form', function () {
    $state = callFromFormClient($this->token, $this->arguments)->json('result.requestState');

    callFromFormClient($this->token, $this->arguments, [
        'inputResponses' => ['create-task' => ['action' => 'decline']],
        'requestState' => $state,
    ])
        ->assertOk()
        ->assertJsonPath('result.isError', true)
        ->assertJsonPath('result.content.0.text', 'The person declined to fill in title, priority, due_on, assignee, so nothing ran. Do not ask again unless they ask.');

    $this->assertDatabaseMissing('tasks', ['title' => 'Book the launch venue']);
});
