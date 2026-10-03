<?php

use App\Enums\TokenAccess;
use App\Models\User;
use Illuminate\Testing\TestResponse;
use Tests\Support\Boards;
use Tests\Support\Sandboxes;
use Tests\Support\StreamParts;

/*
 * The row caps of a hosted demo team (demo.caps.tasks_per_team and projects_per_team). The actions refuse in their own
 * handle(), so the board, the JSON route, MCP clients and the copilot all hear the same sentence, and nothing is saved.
 * Each test sets the cap to what Acme already holds, so the next row is the one past it.
 */

beforeEach(function () {
    config(['demo.hosted' => true]);

    $this->sandbox = Sandboxes::start();
    $this->you = Sandboxes::person($this->sandbox, 'you');
    $this->acme = Sandboxes::team($this->sandbox, 'Acme');

    $this->tasks = $this->acme->tasks()->count();
    $this->projects = $this->acme->projects()->count();
    $this->full = "This demo team holds {$this->tasks} tasks, as many as it can. Delete a few to add more.";

    config(['demo.caps.tasks_per_team' => $this->tasks, 'demo.caps.projects_per_team' => $this->projects]);

    $this->mcp = function (User $user, string $tool, array $arguments): TestResponse {
        $token = $user->createToken('Claude Code', TokenAccess::ReadWrite->abilities($this->acme))->plainTextToken;

        return $this->postJson(Boards::mcpUrl($this->acme), Boards::rpc('tools/call', ['name' => $tool, 'arguments' => $arguments]), Boards::bearer($token));
    };
});

describe('tasks', function () {
    it('refuses the one past the cap from the board', function () {
        $this->actingAs($this->you)
            ->postJson(Boards::url('create-task', $this->acme), ['title' => 'One too many'])
            ->assertConflict()
            ->assertJsonPath('message', $this->full);

        expect($this->acme->tasks()->count())->toBe($this->tasks);
    });

    it('refuses the one past the cap over MCP', function () {
        ($this->mcp)($this->you, 'create-task', ['title' => 'One too many', 'priority' => 'high', 'due_on' => today()->addWeek()->toDateString()])
            ->assertOk()
            ->assertJsonPath('result.isError', true)
            ->assertJsonPath('result.content.0.text', fn (string $text): bool => str_starts_with($text, $this->full));

        expect($this->acme->tasks()->count())->toBe($this->tasks);
    });

    it('refuses the one past the cap from the copilot, which says why', function () {
        $response = $this->actingAs($this->you)->postJson(
            route('assistant', ['current_team' => $this->acme->slug]),
            [
                'id' => 'assistant',
                'messages' => [['id' => 'm1', 'role' => 'user', 'parts' => [['type' => 'text', 'text' => 'Add three launch tasks for Marcus']]]],
                'trigger' => 'submit-message',
            ],
            ['Accept' => 'application/json, text/event-stream'],
        );

        $parts = StreamParts::of($response->assertOk()->streamedContent());

        expect(collect(StreamParts::rows($parts))->pluck('status')->unique()->all())->toBe(['refused'])
            ->and(StreamParts::text($parts))->toBe("I couldn't add that: {$this->full}")
            ->and($this->acme->tasks()->count())->toBe($this->tasks);
    });

    it('makes room again once a task is deleted', function () {
        $this->acme->tasks()->first()->delete();

        $this->actingAs($this->you)
            ->postJson(Boards::url('create-task', $this->acme), ['title' => 'Back in'])
            ->assertOk();
    });

    it('holds no cap on a copy', function () {
        config(['demo.hosted' => false]);

        $this->actingAs($this->you)
            ->postJson(Boards::url('create-task', $this->acme), ['title' => 'One more'])
            ->assertOk();
    });
});

describe('projects', function () {
    it('refuses the one past the cap from the board', function () {
        $this->actingAs($this->you)
            ->postJson(Boards::url('create-project', $this->acme), ['name' => 'One too many'])
            ->assertConflict()
            ->assertJsonPath('message', "This demo team holds {$this->projects} projects, as many as it can.");

        expect($this->acme->projects()->count())->toBe($this->projects);
    });

    it('refuses the one past the cap over MCP', function () {
        ($this->mcp)($this->you, 'create-project', ['name' => 'One too many'])
            ->assertOk()
            ->assertJsonPath('result.isError', true)
            ->assertJsonPath('result.content.0.text', fn (string $text): bool => str_starts_with($text, "This demo team holds {$this->projects} projects"));

        expect($this->acme->projects()->count())->toBe($this->projects);
    });
});
