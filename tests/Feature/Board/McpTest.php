<?php

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Enums\TokenAccess;
use App\Models\Task;
use Illuminate\Testing\TestResponse;
use Tests\Support\Boards;

/*
 * The board over MCP, at /mcp/t/{team}, as an MCP client sees it: a Sanctum token from the "AI clients" page as the
 * bearer credential, JSON-RPC through the real kernel. Then the change feed: a write over MCP reaches a signed-in
 * teammate's open board on its next poll.
 */

beforeEach(function () {
    $this->boards = Boards::make();
});

/**
 * Names of the tools in a tools/list answer.
 *
 * @return list<string>
 */
function mcpToolNames(TestResponse $response): array
{
    return array_column($response->assertOk()->json('result.tools'), 'name');
}

describe('tools/list', function () {
    it('lists the board\'s reads and writes for a read-and-write token, and never delete-task', function () {
        $boards = $this->boards;
        $token = $boards->owner->createToken('Claude Code', TokenAccess::ReadWrite->abilities($boards->acme))->plainTextToken;

        $response = $this->postJson(Boards::mcpUrl($boards->acme), Boards::rpc('tools/list'), Boards::bearer($token));

        expect(mcpToolNames($response))->toBe([
            'complete-task', 'completed-per-day', 'completion-stats', 'create-project', 'create-task', 'list-tasks', 'search-tasks', 'summarize-board', 'task-stats', 'tasks-by-assignee', 'tasks-by-status', 'update-task',
        ]);

        $tools = collect($response->json('result.tools'))->keyBy('name');

        expect($tools['create-task']['annotations'])->toMatchArray(['readOnlyHint' => false, 'destructiveHint' => true])
            ->and($tools['list-tasks']['annotations'])->toMatchArray(['readOnlyHint' => true])
            ->and($tools['complete-task']['inputSchema']['properties'])->toHaveKey('title');
    });

    it('lists only the reads for a read-only token', function () {
        $boards = $this->boards;
        $token = $boards->owner->createToken('Cursor', TokenAccess::Read->abilities($boards->acme))->plainTextToken;

        $response = $this->postJson(Boards::mcpUrl($boards->acme), Boards::rpc('tools/list'), Boards::bearer($token));

        expect(mcpToolNames($response))->toBe(['completed-per-day', 'completion-stats', 'list-tasks', 'search-tasks', 'summarize-board', 'task-stats', 'tasks-by-assignee', 'tasks-by-status']);
    });

    it('lists only what the role allows, whatever the token says', function () {
        $boards = $this->boards;
        $token = $boards->viewer->createToken('Claude Code', TokenAccess::ReadWrite->abilities($boards->acme))->plainTextToken;

        $response = $this->postJson(Boards::mcpUrl($boards->acme), Boards::rpc('tools/list'), Boards::bearer($token));

        expect(mcpToolNames($response))->toBe(['completed-per-day', 'completion-stats', 'list-tasks', 'search-tasks', 'summarize-board', 'task-stats', 'tasks-by-assignee', 'tasks-by-status']);
    });

    it('lists nothing for Sanctum\'s default "*" abilities', function () {
        $token = $this->boards->owner->createToken('Everything')->plainTextToken;

        $response = $this->postJson(Boards::mcpUrl($this->boards->acme), Boards::rpc('tools/list'), Boards::bearer($token));

        expect(mcpToolNames($response))->toBe([]);
    });

    it('turns a request without a token away before the server runs', function () {
        $this->postJson(Boards::mcpUrl($this->boards->acme), Boards::rpc('tools/list'))
            ->assertUnauthorized()
            ->assertHeader('WWW-Authenticate');
    });
});

describe('tools/call', function () {
    it('creates a task as the token\'s person, in the URL\'s team', function () {
        $boards = $this->boards;
        $token = $boards->member->createToken('Claude Code', TokenAccess::ReadWrite->abilities($boards->acme))->plainTextToken;

        $this->postJson(Boards::mcpUrl($boards->acme), Boards::rpc('tools/call', [
            'name' => 'create-task',
            'arguments' => ['title' => 'Book the launch venue', 'assignee' => 'Olivia Park', 'priority' => 'high', 'due_on' => '2031-03-01'],
        ]), Boards::bearer($token))
            ->assertOk()
            ->assertJsonPath('result.isError', false)
            ->assertJsonPath('result.content.0.text', 'Done.');

        $task = Task::query()->where('title', 'Book the launch venue')->sole();

        expect($task->team_id)->toBe($boards->acme->id)
            ->and($task->created_by)->toBe($boards->member->id)
            ->and($task->assignee_id)->toBe($boards->owner->id)
            ->and($task->status)->toBe(TaskStatus::Todo);
    });

    it('answers a refusal as an error result, with the action\'s own sentence', function () {
        $boards = $this->boards;
        $token = $boards->owner->createToken('Claude Code', TokenAccess::ReadWrite->abilities($boards->acme))->plainTextToken;

        $this->postJson(Boards::mcpUrl($boards->acme), Boards::rpc('tools/call', [
            'name' => 'complete-task',
            'arguments' => ['title' => 'Cancel the Elm Street lease'],
        ]), Boards::bearer($token))
            ->assertOk()
            ->assertJsonPath('result.isError', true)
            ->assertJsonPath('result.content.0.text', fn (string $text): bool => str_contains($text, 'No open task has that title.'));

        expect($boards->globexTask->fresh()->status)->toBe(TaskStatus::Todo);
    });

    it('reads a filter left as an empty string as no filter, as the web does', function () {
        $boards = $this->boards;
        $token = $boards->owner->createToken('Claude Code', TokenAccess::Read->abilities($boards->acme))->plainTextToken;

        $this->postJson(Boards::mcpUrl($boards->acme), Boards::rpc('tools/call', [
            'name' => 'list-tasks',
            'arguments' => ['status' => '', 'priority' => ' ', 'assignee' => '', 'project' => ''],
        ]), Boards::bearer($token))
            ->assertOk()
            ->assertJsonPath('result.isError', false)
            ->assertJsonPath('result.content.0.text', fn (string $text): bool => str_contains($text, 'Write the launch announcement'));
    });

    it('keeps a task\'s column and priority when a change leaves them as empty strings', function () {
        $boards = $this->boards;
        $token = $boards->owner->createToken('Claude Code', TokenAccess::ReadWrite->abilities($boards->acme))->plainTextToken;

        $this->postJson(Boards::mcpUrl($boards->acme), Boards::rpc('tools/call', [
            'name' => 'update-task',
            'arguments' => ['task' => $boards->acmeTask->id, 'title' => 'Write the launch post', 'status' => '', 'priority' => ''],
        ]), Boards::bearer($token))
            ->assertOk()
            ->assertJsonPath('result.isError', false);

        $task = $boards->acmeTask->fresh();

        expect($task->title)->toBe('Write the launch post')
            ->and($task->status)->toBe(TaskStatus::Todo)
            ->and($task->priority)->toBe(TaskPriority::High);
    });

    it('refuses a write to a read-only token and saves nothing', function () {
        $boards = $this->boards;
        $token = $boards->owner->createToken('Cursor', TokenAccess::Read->abilities($boards->acme))->plainTextToken;

        $this->postJson(Boards::mcpUrl($boards->acme), Boards::rpc('tools/call', [
            'name' => 'create-task',
            'arguments' => ['title' => 'Should not land'],
        ]), Boards::bearer($token))
            ->assertJsonPath('error.message', 'Tool [create-task] not found.');

        $this->assertDatabaseMissing('tasks', ['title' => 'Should not land']);
    });

    it('never runs delete-task', function () {
        $boards = $this->boards;
        $token = $boards->owner->createToken('Claude Code', [...TokenAccess::ReadWrite->abilities($boards->acme), 'actions:destructive'])->plainTextToken;

        $this->postJson(Boards::mcpUrl($boards->acme), Boards::rpc('tools/call', [
            'name' => 'delete-task',
            'arguments' => ['task' => $boards->acmeTask->id],
        ]), Boards::bearer($token))
            ->assertJsonPath('error.message', 'Tool [delete-task] not found.');

        $this->assertModelExists($boards->acmeTask);
    });
});

describe('other teams', function () {
    it('keeps a token bound to another team out of Acme', function (string $person) {
        $boards = $this->boards;
        $token = $boards->{$person}->createToken('Claude Code', TokenAccess::ReadWrite->abilities($boards->globex))->plainTextToken;

        $list = $this->postJson(Boards::mcpUrl($boards->acme), Boards::rpc('tools/list'), Boards::bearer($token));

        expect(mcpToolNames($list))->toBe([]);

        $this->postJson(Boards::mcpUrl($boards->acme), Boards::rpc('tools/call', [
            'name' => 'create-task',
            'arguments' => ['title' => 'Planted over MCP'],
        ]), Boards::bearer($token))
            ->assertJsonPath('error.message', 'Tool [create-task] not found.');

        $this->assertDatabaseMissing('tasks', ['title' => 'Planted over MCP']);
    })->with([
        'Globex\'s owner, who is not in Acme' => 'globexOwner',
        'a member of both teams' => 'member',
    ]);

    it('keeps a person outside Acme out, even with a token bound to no team', function () {
        $boards = $this->boards;
        $token = $boards->globexOwner->createToken('Claude Code', ['actions:read', 'actions:write'])->plainTextToken;

        expect(mcpToolNames($this->postJson(Boards::mcpUrl($boards->acme), Boards::rpc('tools/list'), Boards::bearer($token))))->toBe([])
            ->and(mcpToolNames($this->postJson(Boards::mcpUrl($boards->globex), Boards::rpc('tools/list'), Boards::bearer($token))))->toContain('create-task');
    });

    it('answers a team that does not exist like one the person is not in', function () {
        $token = $this->boards->owner->createToken('Claude Code', TokenAccess::ReadWrite->abilities($this->boards->acme))->plainTextToken;

        $response = $this->postJson(route('agentic-actions.mcp.tenant', ['current_team' => 'no-such-team']), Boards::rpc('tools/list'), Boards::bearer($token));

        expect(mcpToolNames($response))->toBe([]);
    });
});

describe('the change feed', function () {
    it('brings a write made over MCP to a teammate\'s open board on the next poll', function () {
        $boards = $this->boards;
        $feed = route('actions._changes', ['current_team' => 'acme']);
        $token = $boards->member->createToken('Claude Code', TokenAccess::ReadWrite->abilities($boards->acme))->plainTextToken;

        $first = $this->actingAs($boards->owner)->postJson($feed, ['since' => null])
            ->assertOk()
            ->assertJsonPath('touches', []);
        $since = $first->json('now');

        $this->postJson($feed, ['since' => $since])->assertJsonPath('touches', []);

        $this->app['auth']->forgetGuards();

        $this->postJson(Boards::mcpUrl($boards->acme), Boards::rpc('tools/call', [
            'name' => 'create-task',
            'arguments' => ['title' => 'Written from Claude Code', 'priority' => 'normal', 'due_on' => '2031-03-01'],
        ]), Boards::bearer($token))->assertJsonPath('result.isError', false);

        // The MCP request made sanctum this test's default guard; a browser's next request starts on the session guard.
        $this->app['auth']->forgetGuards();

        $touches = $this->actingAs($boards->owner, 'web')->postJson($feed, ['since' => $since])->assertOk()->json('touches');

        expect($touches)->toEqualCanonicalizing(['tasks', 'summary']);
    });

    it('hears nothing of another team\'s writes', function () {
        $boards = $this->boards;
        $feed = route('actions._changes', ['current_team' => 'acme']);
        $token = $boards->globexOwner->createToken('Claude Code', TokenAccess::ReadWrite->abilities($boards->globex))->plainTextToken;

        $since = $this->actingAs($boards->owner)->postJson($feed, ['since' => null])->json('now');

        $this->app['auth']->forgetGuards();

        $this->postJson(Boards::mcpUrl($boards->globex), Boards::rpc('tools/call', [
            'name' => 'create-task',
            'arguments' => ['title' => 'Globex only', 'priority' => 'normal', 'due_on' => '2031-03-01'],
        ]), Boards::bearer($token))->assertJsonPath('result.isError', false);

        $this->app['auth']->forgetGuards();

        $this->actingAs($boards->owner, 'web')->postJson($feed, ['since' => $since])->assertOk()->assertJsonPath('touches', []);
    });

    it('does not answer a bearer token', function () {
        $token = $this->boards->owner->createToken('Claude Code', TokenAccess::ReadWrite->abilities($this->boards->acme))->plainTextToken;

        $this->postJson(route('actions._changes', ['current_team' => 'acme']), ['since' => null], Boards::bearer($token))
            ->assertStatus(401);
    });
});
